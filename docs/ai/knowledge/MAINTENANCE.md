# Maintenance

Sync `src/Version.php`, changelog, tag. gRPC stub changes need wrapper updates (`SpooledGrpcClient`).

| Change | Update |
| --- | --- |
| Workflow job routes | `contracts/parity.md` |
| Worker lifecycle, retries, realtime auth | `dataflows/client-transport.md`, `risk/chesterton-fences.md` |
| SDK method for a route the backend lacks | `contracts/parity.md` (unsupported list) |
| Webhook signature scheme | `src/Webhooks/Signature.php` (must match backend `outgoing_webhooks/service.rs sign_payload`), README "Verifying a delivery" |
| Worker id examples | ids are global across orgs: README, `docs/{workers,resources,grpc}.md`, `examples/` never pin a fixed id |

