# Transport

- REST Bearer; gRPC `x-api-key`.
- gRPC wrapper: unary only (StreamJobs/ProcessJobs in stubs, not exposed).
- `GrpcOptions` default address is `grpc.spooled.cloud:443` unless overridden.
- `GrpcOptions.timeout` is passed to unary generated stub calls as gRPC microsecond timeout.
- Create/enqueue omits unset retry/timeout values so backend queue/server defaults apply; explicit values are still sent.
- gRPC worker registration sends worker type and SDK version; high-level worker registration uses server field names (`queueName`, `hostname`, `maxConcurrency`).
