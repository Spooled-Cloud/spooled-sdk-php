# Transport

- REST Bearer; gRPC `x-api-key`.
- REST is header-only: the backend 401s credentials passed as `?api_key=`/`?token=`. The sole query-auth routes are `/api/v1/ws` (`WebSocketClient`, JWT only) and the three `/api/v1/events` routes (`SseClient` uses the header anyway). Nothing else may append a credential to a URL — query strings reach proxy/CDN logs, tracing spans, browser history and `Referer` (CWE-598), and API keys do not expire by default.
- gRPC wrapper: unary only (StreamJobs/ProcessJobs in stubs, not exposed).
- `GrpcOptions` default address is `grpc.spooled.cloud:443` unless overridden.
- `GrpcOptions.timeout` is passed to unary generated stub calls as gRPC microsecond timeout.
- Create/enqueue omits unset retry/timeout values so backend queue/server defaults apply; explicit values are still sent.
- gRPC worker registration sends worker type and SDK version; high-level worker registration uses server field names (`queueName`, `hostname`, `maxConcurrency`).
- `WorkerConfig::workerId` (optional, 1-128 chars `[A-Za-z0-9._-]`) is forwarded as `worker_id` and makes REST registration an upsert; omitted means a server-minted UUID per restart, each holding a plan worker-cap slot until the ~2 min stale-worker reaper. Foreign id -> 409.
