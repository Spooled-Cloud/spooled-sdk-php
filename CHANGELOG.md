# Changelog

All notable changes to the Spooled PHP SDK will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
