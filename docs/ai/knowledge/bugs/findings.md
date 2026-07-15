# Findings (PHP SDK)

| ID | Sev | Summary | Evidence |
|----|-----|---------|----------|
| PH-01 | P2 | gRPC enqueue never sets timeoutSeconds | `src/Grpc/SpooledGrpcClient.php` |
| PH-02 | P2 | Default gRPC address localhost:50051 | `src/Grpc/GrpcOptions.php` ~35 |
| PH-03 | P3 | No StreamJobs/ProcessJobs on public client | `SpooledGrpcClient` |
| PH-04 | P3 | CreateJobParams field drift / missing timeout | `src/Types/Job.php` |
| PH-05 | P3 | Worker progress no-op | `src/Worker/JobContext.php` |
