# Transport

- REST Bearer; gRPC `x-api-key`.
- gRPC wrapper: unary only (StreamJobs/ProcessJobs in stubs, not exposed).
- `GrpcOptions` default address `localhost:50051` if unset (`src/Grpc/GrpcOptions.php`) — not prod.
- Enqueue sets `maxRetries` only if isset; **never** `setTimeoutSeconds` in wrapper.
