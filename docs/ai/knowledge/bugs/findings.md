# Findings (PHP SDK)

| ID | Sev | Summary | Evidence |
|----|-----|---------|----------|
| PH-01 | P2 | ~~gRPC enqueue never sets timeoutSeconds~~ **FIXED 1.0.19** | `src/Grpc/SpooledGrpcClient.php` |
| PH-02 | P2 | ~~Default gRPC address localhost:50051~~ **FIXED 1.0.19** | `src/Grpc/GrpcOptions.php` |
| PH-03 | P3 | No StreamJobs/ProcessJobs on public client | `SpooledGrpcClient` |
| PH-04 | P3 | ~~CreateJobParams field drift / missing timeout~~ **FIXED 1.1.0** | `src/Types/Job.php` |
| PH-05 | P3 | ~~Worker progress/log no-op~~ **FIXED 1.1.0** — local job log | `src/Worker/JobContext.php` |
| PH-06 | P3 | ~~gRPC worker register omitted workerType/version~~ **FIXED 1.1.0** | `src/Grpc/SpooledGrpcClient.php` |
| PH-07 | P1 | ~~High-level worker registration used stale REST field names~~ **FIXED 1.1.0** | `src/Worker/SpooledWorker.php` |
| PH-08 | P2 | ~~gRPC timeout option was not passed to unary calls~~ **FIXED 1.1.0** | `src/Grpc/SpooledGrpcClient.php` |
| PH-09 | P2 | ~~Realtime WebSocket example built wrong URL and used raw API key~~ **FIXED 1.1.0** | `examples/realtime-example.php` |
| PS-W1 | P2 | ~~Worker type drift from REST~~ **FIXED 1.1.0** | `src/Types/Worker.php` |
| PH-10 | P1 | ~~`checkEmail` dropped `available`/`signupEnabled`; `canRegister` always true~~ **FIXED** | `src/Types/Auth.php`; backend is `GET /auth/check-email` |
| PH-11 | P2 | ~~`emailStart` dropped `emailSentTo`~~ **FIXED** | `src/Types/Auth.php`; body is `message` + `email_sent_to` |
| PH-12 | P1 | ~~`Organization::$plan` always `free` (API sends `plan_tier`)~~ **FIXED** | `src/Types/Organization.php` |
| PH-13 | P1 | ~~`organizations->create()` dropped the one-time `api_key`~~ **FIXED** | `src/Resources/OrganizationsResource.php` |
| PH-14 | P2 | ~~`checkSlug` looked for `suggestions`; API sends `suggestion`/`valid`/`error`~~ **FIXED** | `src/Resources/OrganizationsResource.php` |
| PH-15 | P1 | ~~`Queue::$name` always empty (API sends `queue_name`)~~ **FIXED** | `src/Types/Queue.php` |
| PH-16 | P1 | ~~`QueueStats` counts always 0 (API sends `pending_jobs` etc.)~~ **FIXED** | `src/Types/Queue.php` |
| PH-17 | P1 | ~~`Queue::$timeout` always null; `$paused` ignored `enabled`~~ **FIXED** | `src/Types/Queue.php` |
| PH-18 | P1 | ~~`ApiKey::$active` always true; `$lastUsedAt` always null (API sends `is_active`/`last_used`)~~ **FIXED** | `src/Types/ApiKey.php` |
| PH-19 | P1 | ~~`WebhookDelivery::$attemptNumber` always 1; `$response` always null (API sends `attempts`/`response_body`)~~ **FIXED** | `src/Types/Webhook.php` |
| PH-20 | P1 | ~~`schedules->history()` always empty; entries dropped `error_message`/`started_at`~~ **FIXED** | `src/Resources/SchedulesResource.php`; `src/Types/Schedule.php` |
| PH-21 | P1 | ~~`webhooks->test()` mapped probe `{success,status_code,response_time_ms}` onto `WebhookDelivery`~~ **FIXED** | `src/Resources/WebhooksResource.php`; `src/Types/Webhook.php` |
| PH-22 | P1 | ~~`Job::$retryCount` always 0 on list/DLQ (API sends `attempt`)~~ **FIXED** | `src/Types/Job.php` |
| PH-23 | P1 | ~~`Job::$error`/`$workerId` always null on get (API sends `last_error`/`assigned_worker_id`)~~ **FIXED** | `src/Types/Job.php` |
| PH-24 | P1 | ~~`DashboardStats` 24h counts and averages always 0 (API sends `completed_24h`/`avg_wait_time_ms`, camelCased)~~ **FIXED** | `src/Types/Common.php` |
| PH-25 | P1 | ~~`workflows->create()` sent `queue`; API requires `queue_name` so documented creates 422~~ **FIXED** | `src/Resources/WorkflowsResource.php` |
| PH-26 | P1 | ~~`workflows->create()` left `name` empty and `totalJobs` 0 (create body is workflowId/jobIds/status)~~ **FIXED** | `src/Resources/WorkflowsResource.php` |
| PH-27 | P1 | ~~`WebhookToken` dropped `webhook_url` from GET/POST `/organizations/webhook-token`~~ **FIXED** | `src/Types/Organization.php` |
| PH-28 | P1 | ~~`jobs->create()` mapped `{id, created}` onto `Job`, dropping `created` and leaving queue/status empty~~ **FIXED** | `src/Resources/JobsResource.php` |
| PH-29 | P1 | ~~`getUsage()` dropped `jobs_today`/`warnings`/`plan_display_name`~~ **FIXED** | `src/Types/Organization.php` |
| PH-30 | P1 | ~~`workflows->get()` left `totalJobs`/`completedJobs`/`failedJobs` at 0~~ **FIXED** | `src/Types/Workflow.php`; GET detail puts counts under `progress` |
| PH-31 | P2 | ~~`Job` dropped `job_type` from `GET /jobs` summaries~~ **FIXED** | `src/Types/Job.php`; `$jobType` from list `job_type` or GET `payload.job_type` |
| PH-32 | P2 | ~~`JobStatus` omitted `processing`/`deadletter` (REST values); had `claimed` instead~~ **FIXED** | `src/Types/Job.php` |
| PH-33 | P1 | ~~`admin->purgeQueue()` POSTed `/admin/queues/{name}/purge` (404)~~ **FIXED** | `src/Resources/AdminResource.php`; `DELETE /queues/{name}?delete_jobs=true` |
| PH-34 | P1 | ~~`admin->deregisterWorker()` DELETEd `/admin/workers/{id}` (404)~~ **FIXED** | `src/Resources/AdminResource.php`; `POST /workers/{id}/deregister` |
| PH-35 | P1 | ~~`admin->cancelJob()` POSTed `/admin/jobs/{id}/cancel` (404)~~ **FIXED** | `src/Resources/AdminResource.php`; `DELETE /jobs/{id}` then GET |
| PH-36 | P1 | ~~`admin->listJobs()` / `getJob()` called `/admin/jobs` (404)~~ **FIXED** | `src/Resources/AdminResource.php`; `GET /jobs` and `GET /jobs/{id}` |
| PH-37 | P1 | ~~`admin->listWorkers()` / `getWorker()` called `/admin/workers` (404)~~ **FIXED** | `src/Resources/AdminResource.php`; `GET /workers` and `GET /workers/{id}` |
| PH-38 | P1 | ~~`admin->listQueues()` called `/admin/queues` (404)~~ **FIXED** | `src/Resources/AdminResource.php`; `GET /queues` |
| PH-39 | P1 | ~~`admin->listSchedules()` called `/admin/schedules` (404)~~ **FIXED** | `src/Resources/AdminResource.php`; `GET /schedules` |
| PH-40 | P1 | ~~`admin->listWorkflows()` called `/admin/workflows` (404)~~ **FIXED** | `src/Resources/AdminResource.php`; `GET /workflows` |
| PH-41 | P1 | ~~`ApiKey::$scopes` always null (API sends `queues`)~~ **FIXED** | `src/Types/ApiKey.php`; list/get `queues` (empty = all) |
| PH-42 | P1 | ~~`auth->me()` mapped `/auth/me` onto `User` (empty id/email)~~ **FIXED** | `src/Types/Auth.php`; session is `organization_id`/`api_key_id`/`queues` |
| PH-43 | P1 | ~~`auth->validate()` dropped `claims` (org/queues/exp always empty)~~ **FIXED** | `src/Types/Auth.php`; POST `/auth/validate` is `{valid,error,claims}` |
| PH-44 | P1 | ~~`auth->logout()` omitted refresh token so `/auth/refresh` survived~~ **FIXED** | `src/Resources/AuthResource.php`; POST `/auth/logout` body `refresh_token` |
| PH-45 | P1 | ~~`workflows->delete()` DELETEd `/workflows/{id}` (404)~~ **FIXED** | `src/Resources/WorkflowsResource.php`; `POST /workflows/{id}/cancel` |
| PH-46 | P1 | ~~`dashboard->getOverview()` / charts / activity hit missing `/dashboard/*` subpaths~~ **FIXED** | `src/Resources/DashboardResource.php`; `GET /dashboard` |
| PH-47 | P1 | ~~`apiKeys->regenerate()` POSTed `/api-keys/{id}/regenerate` (404)~~ **FIXED** | `src/Resources/ApiKeysResource.php`; create replacement then DELETE old |
| PH-48 | P1 | ~~`webhooks->retryDelivery()` mapped `{success,message}` onto `WebhookDelivery`~~ **FIXED** | `src/Resources/WebhooksResource.php`; `src/Types/Webhook.php`; POST retry is success+message, not a delivery |
| PH-49 | P2 | ~~`HealthStatus::$checks` always null (GET `/health` sends `database`/`cache`)~~ **FIXED** | `src/Types/Common.php` |
| PH-50 | P1 | ~~`ingest->custom()` returned `void` and dropped `jobId` after backend started sending `WebhookResponse`~~ **FIXED** | `src/Resources/WebhookIngestionResource.php` |
| PH-51 | P1 | ~~`auth->emailVerify()` mapped signup `{type,signup_token}` onto empty `AuthTokens`~~ **FIXED** | `src/Types/Auth.php`; `src/Resources/AuthResource.php`; POST `/auth/email/verify` is tagged login\|signup |
| PH-52 | P1 | ~~`auth->register()` POSTed `/auth/register` (404)~~ **FIXED** | `src/Resources/AuthResource.php`; `POST /auth/signup/complete` → tokens + raw `api_key` |
| PH-53 | P1 | ~~`organizations->removeMember()` DELETEd `/organizations/{id}/members/{id}` (404)~~ **FIXED** | `src/Resources/OrganizationsResource.php`; members are API keys → `DELETE /api-keys/{id}` |
| PH-54 | P1 | ~~Job/schedule `fromArray` dropped non-object JSON payload/result/tags/metadata~~ **FIXED** | `src/Types/Job.php`; `src/Types/Schedule.php`; backend is `serde_json::Value` |
| PH-55 | P1 | ~~`Job`/`Schedule` dropped `timeout_seconds` (required on GET Job)~~ **FIXED** | `src/Types/Job.php`; `src/Types/Schedule.php` |
| PH-56 | P1 | ~~`WorkflowJob` dropped non-object payload/result, `attempt`, and error `message`~~ **FIXED** | `src/Types/Workflow.php`; GET detail jobs send `attempt`/`timeout_ms`/`error:{message}` |
| SDKGP-19 | P1 | ~~Worker claimed up to `min(concurrency,10)` jobs but ran them serially; leases of jobs 2..N expired while they waited~~ **FIXED 1.3.0** — claims 1 per poll; jobs waiting behind the running one (over-delivery) are renewed by the renewal child | `src/Worker/SpooledWorker.php` |
| SDKGP-20 | P1 | ~~No worker heartbeat while a handler runs; scheduler marked the worker offline after 2 min and released its jobs~~ **FIXED 1.3.0** — the renewal child sends `workers/{id}/heartbeat` | `src/Worker/SpooledWorker.php` |
| SDKGP-21 | P1 | ~~SIGTERM → `stop()` blocked the running job for `shutdownTimeout`, then dropped its ack~~ **FIXED 1.3.0** — `stop()` only flags; job finishes, is acked, worker deregisters last; `shutdownTimeout` aborts via SIGALRM (`ShutdownTimeoutError`) | `src/Worker/SpooledWorker.php` |
| SDKGP-12 | P2 | ~~Fail error text sent unmodified (empty / >2048 chars rejected by `/fail`)~~ **FIXED 1.3.0** — `Util\FailureText::coerce` in `jobs->fail()` and the worker | `src/Util/FailureText.php`; `src/Resources/JobsResource.php` |
| SDKGP-17 | P2 | ~~gRPC `Fail` without `retry` dead-lettered on the first failure~~ **FIXED 1.3.0** — `retry` defaults to true | `src/Grpc/SpooledGrpcClient.php` |
| SDKGP-22 | P2 | ~~`SseClient` spun at 100% CPU (read timeout 0) and reconnected hot after a clean close~~ **FIXED 1.3.0** — 5 s read timeout, idle watchdog, backoff not reset by merely connecting | `src/Realtime/SseClient.php` |
| SDKGP-23 | P2 | ~~HTTP client never retried network errors/timeouts~~ **FIXED 1.3.0** — retried for idempotent requests, acks, and creates with an idempotency key | `src/Util/RetryHandler.php`; `src/Resources/JobsResource.php` |
| SDKGP-24 | P2 | ~~`JobList::hasMore` wrong for default and custom page sizes~~ **FIXED 1.3.0** — compares with the requested/default (50) page size, clamped to 100 | `src/Types/Job.php`; `src/Resources/JobsResource.php` |
| SDKGP-25 | P2 | ~~`realtime()` replaced the REST bearer with a non-refreshing JWT~~ **FIXED 1.3.0** — realtime owns its credentials; WS JWT minted lazily and refreshed per (re)connect | `src/SpooledClient.php`; `src/Realtime/*` |
| SDKGP-26 | P2 | ~~Methods/README examples for routes the backend does not have~~ **FIXED 1.3.0** — deprecated, throw `UnsupportedOperationError` before any request | `src/Resources/{Auth,Organizations,Admin,WebhookIngestion}Resource.php` |
| FESF-01/02 | P1/P2 | ~~`dlq->retry/purge/list` and `bulkEnqueue` ignored `queue`; DLQ purge/retry hit every queue~~ **FIXED 1.3.0** — `queue` mapped to `queueName` | `src/Resources/JobsResource.php` |
| W2-QUOTA | P2 | ~~429 `QUOTA_EXCEEDED` retried with long sleeps and tripped the circuit breaker, blocking acks~~ **FIXED 1.3.0** — `RateLimitError::isQuotaExceeded()` | `src/Errors/RateLimitError.php`; `src/Util/{RetryHandler,CircuitBreaker}.php` |
| W2-TIMEOUT | P2 | ~~Worker ignored the job's `timeout_seconds`~~ **FIXED 1.3.0** — SIGALRM aborts the handler, job failed `Job timeout exceeded`, `job:timeout` event | `src/Worker/SpooledWorker.php` |
| W9-01 | P2 | ~~gRPC `fail(['retry' => false])` never put field 4 on the wire (stub predated `optional bool retry`), so the job was retried, not dead-lettered~~ **FIXED 1.4.0 - stubs regenerated; live: dead-letters with maxRetries 3** | `src/Grpc/Stubs; src/Grpc/SpooledGrpcClient.php` |
| W9-02 | P3 | ~~gRPC register could not send the stable `worker_id` (field 7)~~ **FIXED 1.4.0** | `src/Grpc/SpooledGrpcClient.php` |
| W9-03 | P3 | ~~`JobList::hasMore` guessed from a full page and ignored `X-Has-More`~~ **FIXED 1.4.0** | `src/Resources/JobsResource.php; src/Types/Job.php` |
| W9-04 | P3 | ~~`X-RateLimit-*` dropped on 2xx; README said the API sends none~~ **FIXED 1.4.0 - getLastRateLimit()** | `src/Http/HttpClient.php; src/Types/RateLimitInfo.php` |
| W9-05 | P3 | ~~Wave-8 API not typed: history, inbound log, worker counters, webhook format, list filters~~ **FIXED 1.4.0** | `src/Resources/{Jobs,WebhookIngestion,Webhooks}Resource.php; src/Types` |
| W9-06 | P3 | ~~README realtime example read camelCase `jobId`; SSE data is snake_case~~ **FIXED 1.4.0** | `README.md` |
| W9-07 | P3 | ~~leaseId documented as optional for complete/fail/heartbeat; the API requires it (422)~~ **FIXED 1.4.0 - docs only** | `src/Resources/JobsResource.php; docs` |
| W9-08 | P3 | ~~QueueStats total/cancelled/scheduled/throughput and Job scheduleId/workerName never populated~~ **FIXED 1.4.0 - documented, deprecated; deadletter/maxJobAgeSeconds mapped** | `src/Types/{Queue,Job}.php` |
| W9-09 | P3 | ~~scripts/test-local.php: stale lease/key/metrics/payload checks and production-unsafe tests; test-production.php silent on Windows~~ **FIXED 1.4.0 - 134/144 pass on production, unsafe tests opt-in; org-wide clean-ups: W9-11** | `scripts/test-local.php; scripts/test-production.php` |
| W9-10 | P3 | ~~scripts/generate-grpc.php rewrote the stub namespace to Spooled\Grpc\Stubs (breaks the Spooled\V1 autoload) and needed `which`~~ **FIXED 1.4.0** | `scripts/generate-grpc.php` |
| W9-11 | P2 | ~~scripts/test-local.php deleted up to 100 schedules and cancelled up to 100 unfinished workflows of the whole organization (`cleanupSchedules`/`cleanupWorkflows`, no name check, not behind `RUN_UNSAFE_TESTS`, also in `test-production`); the job clean-up cancelled jobs in any `test-` queue~~ **FIXED 1.4.0 - only names with the run prefix `test-<unix time>-<8 hex>-` (schedule: name and queue); unit-tested** | `scripts/lib/suite-cleanup.php; scripts/test-local.php; tests/Unit/Scripts/SuiteCleanupTest.php` |
