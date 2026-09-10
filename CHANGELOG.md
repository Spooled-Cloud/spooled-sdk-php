# Changelog

All notable changes to the Spooled PHP SDK will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-09-10

Tracks Spooled backend `0.1.112`. A contract-parity pass: every fix below is a
place where this SDK's route, request shape, or response mapping disagreed with
what the API actually serves.

### Fixed

- `organizations->removeMember()` now calls `DELETE /api-keys/{memberId}`.
  There is no members DELETE route; GET `/organizations/{id}/members` is
  backed by active API keys, so the previous path always 404'd.
- `auth->register()` now calls `POST /auth/signup/complete` and returns
  `CompleteSignupResponse` (tokens plus the one-time `api_key` string). It
  previously POSTed `/auth/register`, which is not a backend route, so every
  signup 404'd and would have dropped the raw key even on success.
- `auth->emailVerify()` now returns `EmailVerifyResponse` for the tagged
  `{type: login|signup}` body. It previously mapped onto `AuthTokens`, so a
  new email left `accessToken` empty and dropped `signup_token`.
- `ingest->custom()` now returns `CustomWebhookResponse` (`jobId`, `queueName`,
  `status`). It previously returned `void`, so the created job id was dropped.
  An empty 200 still maps to null fields.
- `health->check()` now maps GET `/health` `database`/`cache` onto
  `HealthStatus::$checks`. Those booleans were previously dropped, so
  component health always read as null.
- `webhooks->retryDelivery()` now returns `RetryDeliveryResponse` (`success`,
  `message`). It previously parsed `POST /outgoing-webhooks/{id}/retry/{delivery_id}`
  as a `WebhookDelivery`, so `id` was empty and `status` was always `pending`.
- `auth->logout()` now sends the refresh token in the body. Without it the
  access token is blacklisted but `/auth/refresh` still mints a new pair, so
  logout did not end the session. The stored refresh token is used when the
  argument is omitted, matching the Node SDK.
- `auth->validate()` now maps `claims.org_id` / `api_key_id` / `queues` / `exp`
  onto `organizationId` / `apiKeyId` / `scopes` / `expiresAt`. It previously
  looked for a nested `user` and top-level `organizationId`, which the API
  never sends, so a valid token looked empty.
- `auth->me()` now returns `CurrentUserResponse` (`organizationId`, `apiKeyId`,
  `queues`, timestamps). It previously mapped GET /auth/me onto `User`, so
  `id`/`email` were always empty; the API never sends those fields.
- `ApiKey::$scopes` now maps `queues` from list/get. The API never sends
  `scopes`, so a queue-restricted key previously looked unrestricted.
- `admin->listWorkflows()` now calls `GET /workflows`. It previously requested
  `/admin/workflows`, which is not a backend route, so every list 404'd.
- `admin->listSchedules()` now calls `GET /schedules`. It previously requested
  `/admin/schedules`, which is not a backend route, so every list 404'd.
- `admin->listQueues()` now calls `GET /queues`. It previously requested
  `/admin/queues`, which is not a backend route, so every list 404'd.
- `admin->listWorkers()` / `getWorker()` now call `GET /workers` and
  `GET /workers/{id}`. They previously requested `/admin/workers`, which is
  not a backend route, so every list or get 404'd.
- `admin->listJobs()` / `getJob()` now call `GET /jobs` and `GET /jobs/{id}`.
  They previously requested `/admin/jobs`, which is not a backend route, so
  every list or get 404'd.
- `admin->cancelJob()` now sends `DELETE /jobs/{id}` and then loads the
  cancelled row. It previously POSTed `/admin/jobs/{id}/cancel`, which is not
  a backend route, so every cancel 404'd.
- `admin->deregisterWorker()` now sends `POST /workers/{id}/deregister`.
  It previously DELETEd `/admin/workers/{id}`, which is not a backend route,
  so every deregister 404'd. DELETE on the real path 405s.
- `admin->purgeQueue()` now sends `DELETE /queues/{name}?delete_jobs=true`.
  It previously POSTed `/admin/queues/{name}/purge`, which is not a backend
  route, so every purge 404'd.
- `auth->checkEmail()` now maps `available` and `signupEnabled` from
  `GET /auth/check-email`. It previously read `canRegister` (never sent) and
  defaulted it to true, so a closed signup still looked open.
- `auth->emailStart()` now maps `emailSentTo` from `POST /auth/email/start`.
  It previously dropped that field and typed `codeExpiresIn`, which the API
  never sends.
- `Organization` now reads `planTier` (`plan_tier`). It previously looked for
  `plan`, which the API never sends, so every org looked like the free plan.
- `organizations->create()` now returns `CreateOrganizationResponse` with the
  organization and the one-time initial API key. It previously returned only
  `Organization` and dropped `api_key`, so the key the API shows once was
  discarded.
- `organizations->checkSlug()` now maps `valid`, `error`, and `suggestion`
  from `GET /organizations/check-slug`. It previously looked for
  `suggestions` (plural), which the API never sends.
- `Queue` now reads `queueName` (`queue_name`). It previously looked for
  `name`, which list/get never send, so every queue looked unnamed.
- `QueueStats` now reads `pendingJobs`, `processingJobs`, `completedJobs24h`,
  `failedJobs24h`, and `avgProcessingTimeMs`. It previously looked for
  `pending`/`claimed`/`completed`/`failed`/`avgProcessingTime`, which
  `GET /queues/{name}/stats` never sends, so every count was 0.
- `Queue` now reads `defaultTimeout` and derives `paused` from `enabled` when
  `paused` is absent. List/get send those fields, so timeout was always null
  and a disabled queue looked unpaused.
- `ApiKey` now reads `isActive` and `lastUsed`. It previously looked for
  `active` and `lastUsedAt`, which list/get never send, so a revoked key
  looked active and last-used was always null.
- `WebhookDelivery` now reads `attempts` and `responseBody`. It previously
  looked for `attemptNumber`/`attempt` and `response`, which deliveries
  never send, so every delivery looked like attempt 1 with an empty body.
- `schedules->history()` now parses the raw array from
  `GET /schedules/{id}/history` and maps `errorMessage`/`startedAt`. It
  previously looked for a `history` wrapper and `error`/`executedAt`, so
  history was always empty and a run's error and time were always null.
- `webhooks->test()` now returns `TestWebhookResponse` (`success`,
  `statusCode`, `responseTimeMs`, `error`). It previously parsed the probe
  as a `WebhookDelivery`, so `success` was missing and status was always
  `pending`.
- `Job::$retryCount` now maps `attempt` from `GET /jobs` and `GET /jobs/dlq`.
  Those list bodies send `attempt`, not `retryCount`, so every listed job
  looked like it had never been retried.
- `Job::$error` and `$workerId` now map `lastError` and `assignedWorkerId`
  from `GET /jobs/{id}`. Those fields were never sent as `error`/`workerId`,
  so a failed job looked like it had no error and no worker.
- `DashboardStats` now maps `jobs.completed24h` / `failed24h` /
  `avgWaitTimeMs` / `avgProcessingTimeMs` from `GET /dashboard`. It previously
  looked for `completed_24h` / `avg_wait_time_ms` after the HTTP client had
  already camelCased those keys, so 24h counts and averages always read 0.
- `workflows->create()` now maps each job's documented `queue` alias to
  `queueName` (wire `queue_name`). The README and examples send `queue`, which
  the API ignores, so every documented create previously 422'd.
- `workflows->create()` now backfills `name` and `totalJobs` from the request.
  `POST /workflows` only returns `workflowId`/`jobIds`/`status`, so those
  fields previously read as empty and 0.
- `WebhookToken::$url` now maps `webhookUrl` from GET/POST
  `/organizations/webhook-token`. The API always sends the inbound URL; the
  SDK previously dropped it.

**Breaking:** `Webhook::$failedCount` is renamed to `Webhook::$failureCount`. The old property was mapped from a response key the API never sends, so it always read 0; the new one carries the real consecutive-failure count.
**Breaking:** `webhooks->test()` now returns `TestWebhookResponse` instead of `WebhookDelivery`. The test endpoint never sent a delivery.
**Breaking:** `webhooks->retryDelivery()` now returns `RetryDeliveryResponse` instead of `WebhookDelivery`. The retry endpoint never sent a delivery.
**Breaking:** `jobs->create()` now returns `CreateJobResult` (`id`, `created`) instead of `Job`. `POST /jobs` only sends those two fields, so queue/status/payload on the old `Job` were always empty and the idempotency `created` flag was dropped. Use `createAndGet()` for a full `Job`.
- `OrganizationUsage` now maps `jobsToday`, `workflows`, `planDisplayName`,
  `warnings`, and per-item `percentage`/`isDisabled` from
  `GET /organizations/usage`. Those fields were previously dropped, so daily
  job quota was invisible.

- `Schedule::$tags` is now read from `GET /schedules/{id}` and backfilled from a
  `create()` call. `schedules.tags` exists on the API but the type dropped it.
- `ScheduleHistoryEntry::$completedAt` is now read from `schedule_runs.completed_at`,
  so a run's finish time is no longer discarded.
- `Worker::$metadata`, `Workflow::$metadata` and `WebhookDelivery::$payload`
  accept any JSON. They coerced anything that was not a PHP array to `null`
  (or `[]`), so an array or scalar value from the API was silently dropped;
  all three are `serde_json::Value` on the backend.
- Job list and DLQ summaries read the `job_type` and `last_error` that backend
  `0.1.112` adds to `JobSummary`.

## [1.1.0] - 2026-08-16

### Added

- Optional stable `worker_id` on worker registration. Supplying the same id
  across restarts makes registration an upsert, so a restarting worker reuses
  its row instead of leaving the old one against the plan worker cap until the
  stale-worker reaper clears it (~2 minutes). Omitting it keeps the previous
  behaviour of a server-minted UUID. An id owned by another organization
  returns 409.
- `auto_disabled` in the outgoing-webhook `last_status` domain. The backend now
  disables a webhook after 20 consecutive failed deliveries; re-enable it by
  setting `enabled: true`, which is charged against the plan webhook cap and can
  therefore return `429 QUOTA_EXCEEDED`.

### Changed

- Webhook updates can now express clearing the signing secret distinctly from
  leaving it alone. Backend 0.1.111 treats an explicit `null` as a destructive
  clear, so an untouched secret must be omitted rather than serialised.
- `failure_count` on outgoing webhooks is now counted once per delivery rather
  than once per retry attempt, so for the same failures it is roughly 5x smaller
  than before.
- Documented that `last_used` on API keys is coarse (written at most once per
  key per five minutes) and that webhook delivery history is retained by plan
  rather than kept indefinitely.

### Note

- Backend 0.1.111 no longer accepts API keys in the query string on REST
  endpoints. This SDK sends credentials as an `Authorization` header on REST;
  SSE and WebSocket connections continue to use the query string, which the
  backend still supports for those routes.

## [1.0.21] - 2026-07-19

**Breaking:** although shipped as a patch per this SDK's release precedent, this release changes the public worker API: `Worker` / `WorkerList` properties are renamed to the REST field names (`queueName`, `queueNames`, `maxConcurrency`, `currentJobs`, `registeredAt`, `updatedAt` replace `name`, `queues`, `concurrency`, `activeJobs`, `completedJobs`, `failedJobs`, `pid`, `createdAt`), `workers->heartbeat()` now returns `void` (was `Worker`), and `workers->update()` is removed (no matching backend endpoint).

### Fixed

- `Worker` / `WorkerList` now map the public REST worker fields (`queueName`, `queueNames`, `maxConcurrency`, `currentJobs`, `registeredAt`, `updatedAt`); `workers->heartbeat()` returns void (empty 200 body).
- `CreateJobParams` now omits unset retry/timeout defaults so backend queue/server policy can apply; explicitly provided values are still sent.
- gRPC worker registration now sends `workerType` and `version` instead of relying on server/proto defaults.
- The high-level worker registration payload now uses the server field names (`queueName`, `hostname`, `maxConcurrency`, configured `workerType`).
- gRPC unary calls now pass the configured timeout through generated stub call options.
- Documentation version literals now match `Spooled\Version::VERSION`.
- Worker `progress()` and `log()` now emit local job logs instead of silently no-oping.
- The realtime WebSocket example now uses a base WS URL plus JWT access token instead of appending `/api/v1/ws` and passing a raw API key.

## [1.0.20] - 2026-07-15

### Fixed

- Version surface test tracks `1.0.20` (release `1.0.19` tag failed CI before Packagist publish).

## [1.0.19] - 2026-07-15

### Fixed

- gRPC enqueue now sets `timeoutSeconds` when provided.
- Default gRPC address is `grpc.spooled.cloud:443` (was `localhost:50051`).
- `CreateJobParams` emits API `scheduledAt` / `timeoutSeconds`; `JobsResource::create` maps `scheduledFor` → `scheduledAt`.

### Added

- Agent knowledge base under `docs/ai/knowledge/` (+ KB sync Cursor rule).

## [1.0.18] - 2026-07-14

### Fixed

- Release workflow validates package identity before publishing.

### Changed

- Docs: refresh SDK integration guides.

## [1.0.17] - 2026-07-12

### Fixed

- `SpooledWorker` now reliably renews leases while blocking synchronous handlers
  run on PHP 8.2+, using the configured `heartbeatFraction` and the immutable
  lease ID without introducing an event-loop contract. Each renewal attempt
  creates an isolated post-fork HTTP transport with retries disabled and a
  request timeout bounded by the remaining lease plus a safety margin. Renewal
  children install dedicated signal handlers, detect orphaning, and are stopped
  with bounded TERM/KILL/reap cleanup before settlement. Terminal lease loss
  cancels the execution and is surfaced through the worker `error` event and
  logger.
- Completion and failure rejections are no longer swallowed or reported as
  successful settlements. Success counters/events advance only after the API
  confirms settlement; rejected settlements emit a contextual worker `error`.
- gRPC `renewLease()` now serializes the caller's `extensionSecs`. Normal-suite
  wire tests cover dequeue, complete, fail, and renew lease fields.
- HTTP `User-Agent` and default worker registration now read version `1.0.17`
  from the same package version source.

### Changed

- `SpooledWorker` requires `ext-pcntl` and `ext-posix` in its CLI environment for
  automatic synchronous-handler renewal. Other SDK clients remain unaffected.

## [1.0.16] - 2026-07-11

### Added

- Lease fencing (backend v0.1.94, audit F9). `ClaimedJob` and `Job` now carry
  the `lease_id` fencing token returned by claim/dequeue (exposed as
  `$leaseId`), and the worker echoes it back on complete/fail so an operation
  from a stale lease is rejected with `409 LEASE_EXPIRED` instead of clobbering
  another worker's job. Manual worker loops can opt in by passing `leaseId` in
  the params of `$client->jobs->complete()/fail()/heartbeat()`; omitting it
  keeps the legacy worker-id-only behaviour.
- gRPC client: `complete`, `fail`, and `renewLease` accept an optional
  `leaseId` param and set it on the request; `dequeue` and `getJob` surface
  the job's `leaseId`. Stubs regenerated from the shared proto
  (`Job.lease_id`, `CompleteRequest.lease_id`, `FailRequest.lease_id`,
  `RenewLeaseRequest.lease_id`).

### Fixed

- Default `User-Agent` now reports the current SDK version (it was stuck at
  `spooled-php/1.0.12`).

## [1.0.15] - 2026-07-09

### Fixed

- Realtime WebSocket typed handlers now fire. The backend emits events whose
  `type` is the PascalCase enum variant (e.g. `JobCompleted`); the client now
  maps these to the SDK's dotted event names (`job.completed`,
  `job.status_changed`, `queue.stats`, `worker.heartbeat`, etc.) before
  dispatching, so handlers registered via `on('job.completed', ...)` receive
  events. The catch-all `on('message', ...)` handler still fires for every
  message.
- Realtime WebSocket `subscribe`/`unsubscribe` now send the backend's
  `ClientCommand` shape `{cmd: 'subscribe', queue, job_id}` instead of the
  previous `{action, topic}` form. Subscriptions no longer wait on a
  subscribe acknowledgement (the server never sends one).
- HTTP `400` responses now throw `ValidationError`, matching the production
  backend which returns `400` (code `VALIDATION_ERROR`) for job/queue
  validation failures, and matching the Go SDK. `422` continues to map to
  `ValidationError`; both preserve the real HTTP status code.

## [1.0.14] - 2026-07-09

### Changed

- No functional changes over 1.0.13. Formatting-only cleanup (php-cs-fixer:
  blank line before `return`). Published as a new version because Packagist
  stable versions are immutable — a re-tag of 1.0.13 to carry this cosmetic fix
  was (correctly) blocked, so the tree is realigned and the fix ships as 1.0.14.

## [1.0.13] - 2026-07-09

### Fixed

- **Credentials are trimmed of surrounding whitespace.** API keys, access tokens,
  and refresh tokens read from a file or environment variable often carry a
  trailing newline; the client now trims them at config resolution (an
  all-whitespace value is treated as unset). Prevents a cryptic failure such as
  Go's `net/http: invalid header field value` on a newline-tainted key.

## [1.0.12] - 2026-07-08

### Changed

- **`realtime()` now caches the exchanged JWT instead of logging in on every
  call/reconnect**: when only an API key is configured, `SpooledClient::realtime()`
  exchanges it for a JWT via `POST /api/v1/auth/login`. Previously this exchange
  ran again on each realtime setup, so reconnect storms could hammer the login
  endpoint and hit its `429` rate limit. The JWT is now cached on the client
  instance and reused across `realtime()` calls and reconnects until it nears
  expiry. Expiry is read from the JWT `exp` claim by base64-decoding the payload
  (no signature verification) and treating the token as expired ~60s early;
  a fresh login happens only when no token is cached or the cached one is at/near
  expiry. An explicitly configured access token continues to be used verbatim.
- A `null` refresh token returned by the login endpoint is now handled safely
  during the exchange (it is no longer forwarded to `setRefreshToken()`).

## [1.0.11] - 2026-07-08

### Fixed

- **`schedules->list()` always returned an empty list**: the API
  `GET /api/v1/schedules` responds with a bare top-level JSON array
  (`[{...}, {...}]`), but `ScheduleList::fromArray()` only read the wrapped keys
  (`schedules`/`data`), which are absent for a bare array. Existing schedules
  were therefore silently invisible to SDK users, with no error raised. Confirmed
  by a live production test. `ScheduleList::fromArray()` now detects a bare array
  and parses it, mirroring the handling already present in `JobList` and
  `WorkflowList`, while still supporting the wrapped `{"schedules": [...]}` shape.
- **Same silent-empty-list bug in sibling list types**: the backend list
  endpoints for API keys, queues, workers, outgoing webhooks, webhook deliveries
  and organizations all return bare top-level arrays as well. `ApiKeyList`,
  `QueueList`, `WorkerList`, `WebhookList`, `WebhookDeliveryList` and
  `OrganizationList` `fromArray()` methods now handle the bare-array shape too.
  (`JobList` and `WorkflowList` already handled it and were left unchanged.)

## [1.0.10] - 2026-07-08

### Fixed

- **Schedules could not be created**: `SchedulesResource::create()` (and
  `update()`) sent the SDK's documented parameter aliases through unmapped, so
  every schedules example in the README failed with HTTP 422. The aliases
  `queue`, `schedule`/`cron` and `payload` are now mapped to the API's
  `queue_name`, `cron_expression` and `payload_template` fields, mirroring how
  `JobsResource::create()` maps `queue`. The canonical field names
  (`queueName`/`cronExpression`/`payloadTemplate`) still pass through unchanged.
- **`schedules->create()` returned a Schedule with `timezone = null`**: the
  create endpoint returns only `{id, name, cron_expression, next_run_at}` and
  never echoes `timezone` (nor `queue_name`/`payload_template`). Those fields are
  now backfilled from the request so the returned Schedule matches a follow-up
  `schedules->get()`; `timezone` falls back to the backend default `UTC` when the
  caller omits it.

## [1.0.9] - 2026-07-08

### Fixed

- **Opaque job payloads were corrupted**: the client recursively snake_cased/
  camelCased the entire request/response, mangling user `payload`/`result`/
  `metadata` keys and breaking cross-SDK interop. Those subtrees are now sent
  byte-for-byte.
- **Circuit breaker** tripped on every 4xx (it checked a non-existent method); it now
  only counts 429/5xx/network errors.
- **SSE** authenticates with `Authorization: Bearer` (the backend ignores `X-API-Key`).
- **WebSocket** connects only with a JWT `?token=` and fails loudly if no access token
  is available (the ignored `?api_key=` fallback was removed).
- **Reconnect** no longer re-enters the React event loop (`Loop::run()`).

## [1.0.8] - 2026-07-07

### Security

- Raised dependency security floors for `protobuf` and `guzzlehttp/guzzle`
  (and dev tooling).

### Documentation

- Use the real `sp_live_` / `sp_test_` key prefix in the README examples.

## [1.0.7] - 2025-12-21

### Changed

- Added Live Demo (SpriteForge) link to README

## [1.0.6] - 2025-12-20

### Fixed

- Corrected docs URL to spooled.cloud/docs

## [1.0.5] - 2025-12-19

### Changed

- Added tag filtering example for job listing in README

## [1.0.4] - 2025-12-19

### Fixed

- Removed trailing newlines across source files, docs, and CI config

## [1.0.3] - 2025-12-18

### Fixed

- Use fake test keys (`sp_test_*`) in gRPC tests to avoid GitHub push protection

## [1.0.2] - 2025-01-18

### Fixed
- Fixed PHP CS Fixer code style issues (import statements, string quotes)
- Added missing `@group grpc` and `@group realtime` test annotations for CI
- Added unit tests for gRPC options and SSE event parsing
- Fixed CI exit code issue by adding `--no-coverage` to composer test scripts

### Added
- `tests/Unit/Grpc/GrpcClientTest.php` - gRPC options unit tests
- `tests/Unit/Realtime/SseClientTest.php` - SSE event parsing unit tests
- `examples/error-handling.php` - Comprehensive error handling example

### Documentation
- Added `docs/configuration.md` - All configuration options guide
- Added `docs/grpc.md` - High-performance gRPC transport guide
- Added `docs/workers.md` - SpooledWorker runtime guide
- Added `docs/workflows.md` - DAG workflows guide
- Added `docs/resources.md` - Complete API reference

## [1.0.0] - 2025-01-18

### Added
- Initial SDK implementation with full REST API support
- Jobs resource: create, get, list, cancel, retry, boost priority, batch operations
- Queues resource: list, get, update config, stats, pause/resume, delete
- Workers resource: list, register, heartbeat, deregister
- Schedules resource: CRUD, pause/resume, trigger, history
- Workflows resource: CRUD, cancel, retry, job dependencies
- Webhooks resource: CRUD, test, deliveries, retry delivery
- API Keys resource: CRUD
- Organizations resource: CRUD, usage, members, webhook tokens
- Admin resource: all administrative operations
- Auth resource: login, refresh, logout, email verification flows
- Dashboard, Health, and Metrics resources
- Webhook ingestion with GitHub and Stripe signature validation
- Worker runtime with polling, concurrency, heartbeats, and graceful shutdown
- Realtime support (SSE and WebSocket clients)
- Optional gRPC transport support
- Retry policy with exponential backoff and jitter
- Circuit breaker pattern implementation
- Comprehensive error handling with typed exceptions
- Full test coverage for core functionality
- Script parity with Node.js and Python SDKs

### Client Surface (Node.js/Python Parity)
- `SpooledClient::grpc()` - Lazy-initialized gRPC client access
- `SpooledClient::realtime()` - Unified realtime client (SSE/WebSocket)
- `SpooledClient::getCircuitBreakerStats()` - Circuit breaker metrics
- `SpooledClient::close()` - Clean up all connections

### Authentication
- Bearer token authentication for API keys (matches Node.js/Python SDKs)
- JWT token support with automatic refresh
- Admin key support for administrative operations

### Webhook Ingestion (Node.js Parity)
- `POST /webhooks/{orgId}/github` - GitHub webhook ingestion
- `POST /webhooks/{orgId}/stripe` - Stripe webhook ingestion
- `POST /webhooks/{orgId}/custom` - Custom webhook ingestion
- Signature validation helpers for GitHub and Stripe

### Examples
- `basic-usage.php` - Getting started with the SDK
- `worker-example.php` - Job processing with SpooledWorker
- `workflow-example.php` - DAG workflows with dependencies
- `scheduled-jobs.php` - Cron schedules
- `grpc-example.php` - High-performance gRPC transport
- `realtime-example.php` - SSE/WebSocket event streaming
- `webhook-ingestion-example.php` - Webhook validation and ingestion
