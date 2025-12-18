# Changelog

All notable changes to the Spooled PHP SDK will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2024-12-18

### Fixed
- Fixed PHP CS Fixer code style issues (import statements, string quotes)
- Added missing `@group grpc` and `@group realtime` test annotations for CI
- Added unit tests for gRPC options and SSE event parsing

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

## [1.0.0] - 2024-12-18

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
