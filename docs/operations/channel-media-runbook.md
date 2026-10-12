# Channel media pipeline (P3)

## Queues

| Queue | Jobs |
| --- | --- |
| **`media`** | `ProcessInboundMediaAttachment`, `ResolveCustomerChannelProfileJob` |
| **`outbound`** | `ProcessOutboundChannelMedia`, WhatsApp media send jobs |

Supervisor: `deploy/supervisor/hasem-worker-media.conf`

## Storage

- Configure `MEDIA_DISK` (`local` for dev/tests, `s3` or `media` disk alias for production).
- Objects are **private**; clients receive **temporary/signed URLs** via `MessageAttachmentService::downloadUrl()`.
- Never expose AWS keys in API responses or logs.

## Inbound flow

Webhook → persist `Message` → create `message_attachments` (`pending`) → `ProcessInboundMediaAttachment` → object storage → `stored` → optional realtime refresh.

## Cleanup (deferred)

Scheduled retention for failed/abandoned media is **not** auto-deleted in P3. Plan a P5 job using `config('media.retention.failed_download_days')`.

## Malware scanning

Not bundled in this repo. Treat as an operational add-on (bucket policy, async scanner, or WAF) if required by compliance.
