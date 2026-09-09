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
