# Parity notes (PHP)

- Unique: webhook enable/disable + GitHub/Stripe **validate** helpers. `enable()`/`disable()` are thin wrappers over `PUT /outgoing-webhooks/{id}` (`{"enabled": …}`); there is no `/enable` or `/disable` route to point them back at. `enable()` is the recovery path after auto-disable and is charged against the plan webhook cap (429 `QUOTA_EXCEEDED`).
- `webhooks->update()` `secret` is three-state and null is destructive: omit = keep, `null` = clear (deliveries unsigned), string = replace. Do not "simplify" null back into no-op — that reinstates the un-removable-secret bug.
- `Webhook::$failureCount` maps `failure_count` (consecutive failed **deliveries**, not attempts; 20 -> auto-disable, `$lastStatus === 'auto_disabled'`). `$secret` is always null (backend `skip_serializing`) and `$deliveryCount`/`$maxRetries`/`$timeout`/`$headers` have no REST counterpart.
- Typed `CreateJobParams`: `queue`/`scheduledFor`, default maxRetries 3, **no timeoutSeconds**; `toArray` may emit `scheduledFor` vs API `scheduledAt` — verify mapping in JobsResource.
- Worker progress/log emits local job logs only; Go remains the SDK with backend-persisted `POST /jobs/{id}/progress`. Lease renew needs pcntl/posix.
- Workflow job list/get/status are not their own REST routes. `GET /workflows/{id}` carries jobs + dependencies; `workflows->jobs->list()` reads that document. `POST /jobs/{id}/dependencies` takes `depends_on` + `dependency_mode` and returns `dependencies_added` / `dependencies_met`.
- `GET /jobs/{id}/dependencies` is `{ jobId, dependencies, dependents, dependenciesMet }` with `{ jobId, queueName, status }` edges. `isMet` is derived from `status === completed`.
- Email availability is `GET /auth/check-email?email=`. The body is `available`, `exists`, `signupEnabled`. `canRegister` is derived (`available && signupEnabled`); the API does not send it.
- Email login start is `POST /auth/email/start` → `{ message, emailSentTo }`. `success` is derived (HTTP 200); the API does not send it.
- Organization JSON uses `plan_tier` (camelCase `planTier`), not `plan`. `GET /organizations/usage` is the exception: that body uses `plan`.
- `POST /organizations` returns `{ organization, api_key }`. The key is shown once; `organizations->create()` returns `CreateOrganizationResponse`.
- Slug check is `GET /organizations/check-slug?slug=` → `{ available, valid, error, suggestion }`, not `suggestions`.
- Queue JSON uses `queue_name` (camelCase `queueName`), not `name`. List/get send `enabled` and `default_timeout`; `paused` is on pause/get-info only.
- Queue stats are `pending_jobs`, `processing_jobs`, `completed_jobs_24h`, `failed_jobs_24h`, `avg_processing_time_ms`, not `pending`/`claimed`/`completed`.
- API key JSON uses `is_active` (camelCase `isActive`) and `last_used` (`lastUsed`), not `active`/`lastUsedAt`. List/get never send `prefix`; create returns `key` once.
