# Parity notes (PHP)

- Unique: webhook enable/disable + GitHub/Stripe **validate** helpers. `enable()`/`disable()` are thin wrappers over `PUT /outgoing-webhooks/{id}` (`{"enabled": …}`); there is no `/enable` or `/disable` route to point them back at. `enable()` is the recovery path after auto-disable and is charged against the plan webhook cap (429 `QUOTA_EXCEEDED`).
- `webhooks->update()` `secret` is three-state and null is destructive: omit = keep, `null` = clear (deliveries unsigned), string = replace. Do not "simplify" null back into no-op — that reinstates the un-removable-secret bug.
- `Webhook::$failureCount` maps `failure_count` (consecutive failed **deliveries**, not attempts; 20 -> auto-disable, `$lastStatus === 'auto_disabled'`). `$secret` is always null (backend `skip_serializing`) and `$deliveryCount`/`$maxRetries`/`$timeout`/`$headers` have no REST counterpart.
- Typed `CreateJobParams`: `queue`/`scheduledFor`, default maxRetries 3, **no timeoutSeconds**; `toArray` may emit `scheduledFor` vs API `scheduledAt` — verify mapping in JobsResource.
- Worker progress/log emits local job logs only; Go remains the SDK with backend-persisted `POST /jobs/{id}/progress`. Lease renew needs pcntl/posix.
