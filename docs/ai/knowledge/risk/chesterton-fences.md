# Fences

- Do not mutate published tags (historical v1.0.13→v1.0.14 recovery).
- Never make `stop()` wait. It is called from signal handlers that run on top of the running handler, so waiting for the job can never succeed (it blocked for `shutdownTimeout` and then the finished job's ack was dropped).
- Never claim more jobs than the worker runs now: handlers are serial, and the extra jobs' leases expire while earlier handlers run.
- Never code a fork's child path to return: `runLeaseRenewalLoop` ends in SIGKILL of itself (or `signalLeaseLoss`); an exception escaping it would run the job a second time in the child.
- Never set the exchanged realtime JWT on the shared `HttpClient`.
- Do not reset `SseClient` reconnect attempts on connect.
