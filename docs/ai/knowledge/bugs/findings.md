# Findings (PHP SDK)

| ID | Sev | Summary | Evidence |
|----|-----|---------|----------|
| PH-01 | P2 | ~~gRPC enqueue never sets timeoutSeconds~~ **FIXED 1.0.19** | `src/Grpc/SpooledGrpcClient.php` |
| PH-02 | P2 | ~~Default gRPC address localhost:50051~~ **FIXED 1.0.19** | `src/Grpc/GrpcOptions.php` |
| PH-03 | P3 | No StreamJobs/ProcessJobs on public client | `SpooledGrpcClient` |
| PH-04 | P3 | ~~CreateJobParams field drift / missing timeout~~ **FIXED 1.0.21** | `src/Types/Job.php` |
| PH-05 | P3 | ~~Worker progress/log no-op~~ **FIXED 1.0.21** — local job log | `src/Worker/JobContext.php` |
| PH-06 | P3 | ~~gRPC worker register omitted workerType/version~~ **FIXED 1.0.21** | `src/Grpc/SpooledGrpcClient.php` |
| PH-07 | P1 | ~~High-level worker registration used stale REST field names~~ **FIXED 1.0.21** | `src/Worker/SpooledWorker.php` |
| PH-08 | P2 | ~~gRPC timeout option was not passed to unary calls~~ **FIXED 1.0.21** | `src/Grpc/SpooledGrpcClient.php` |
| PH-09 | P2 | ~~Realtime WebSocket example built wrong URL and used raw API key~~ **FIXED 1.0.21** | `examples/realtime-example.php` |
| PS-W1 | P2 | ~~Worker type drift from REST~~ **FIXED 1.0.21** | `src/Types/Worker.php` |
