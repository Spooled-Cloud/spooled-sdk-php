# Changelog

All notable changes to the Spooled PHP SDK will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
