# Parity notes (PHP)

- Unique: webhook enable/disable + GitHub/Stripe **validate** helpers.
- Typed `CreateJobParams`: `queue`/`scheduledFor`, default maxRetries 3, **no timeoutSeconds**; `toArray` may emit `scheduledFor` vs API `scheduledAt` — verify mapping in JobsResource.
- Worker progress no-op; lease renew needs pcntl/posix.
