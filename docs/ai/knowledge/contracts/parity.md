# Parity notes (PHP)

- Unique: webhook enable/disable + GitHub/Stripe **validate** helpers.
- Typed `CreateJobParams`: `queue`/`scheduledFor`, default maxRetries 3, **no timeoutSeconds**; `toArray` may emit `scheduledFor` vs API `scheduledAt` — verify mapping in JobsResource.
- Worker progress/log emits local job logs only; Go remains the SDK with backend-persisted `POST /jobs/{id}/progress`. Lease renew needs pcntl/posix.
