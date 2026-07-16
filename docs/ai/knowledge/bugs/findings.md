# Findings (PHP SDK)

| ID | Sev | Summary | Evidence |
|----|-----|---------|----------|
| PH-01 | P2 | ~~gRPC enqueue never sets timeoutSeconds~~ **FIXED 1.0.19** | `src/Grpc/SpooledGrpcClient.php` |
| PH-02 | P2 | ~~Default gRPC address localhost:50051~~ **FIXED 1.0.19** | `src/Grpc/GrpcOptions.php` |
| PH-03 | P3 | No StreamJobs/ProcessJobs on public client | `SpooledGrpcClient` |
| PH-04 | P3 | ~~CreateJobParams field drift / missing timeout~~ **FIXED working tree** | `src/Types/Job.php` |
| PH-05 | P3 | Worker progress no-op | `src/Worker/JobContext.php` |
| PH-06 | P3 | ~~gRPC worker register omitted workerType/version~~ **FIXED working tree** | `src/Grpc/SpooledGrpcClient.php` |
| PH-07 | P1 | ~~High-level worker registration used stale REST field names~~ **FIXED working tree** | `src/Worker/SpooledWorker.php` |
| PH-08 | P2 | ~~gRPC timeout option was not passed to unary calls~~ **FIXED working tree** | `src/Grpc/SpooledGrpcClient.php` |
