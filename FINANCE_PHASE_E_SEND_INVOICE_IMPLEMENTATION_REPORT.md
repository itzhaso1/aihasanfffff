# FINANCE PHASE E IMPLEMENTATION REPORT

Send Invoice / Email Delivery

## 1. Summary

Phase E adds customer-facing **email sending** for issued Finance **sales** invoices. Invoice document status, payment status, GL, and ZATCA are unchanged. Delivery lives only on `finance_document_deliveries`.

What now works:

- `invoices.send` permission
- Invoice show page: **إرسال بالبريد الإلكتروني**
- Issued sales invoices only (draft must be issued first; cancelled cannot send; purchase invoices are not customer-facing sends)
- Customer email prefills; sender may override for this send only (customer record is not overwritten)
- Optional phone is stored on the delivery row only; it is never used for WhatsApp or SMS
- Subject / message with Arabic defaults including invoice number, date, total, company name
- Invoice PDF attached by default (`invoice-{number}.pdf`) via existing `PdfInvoiceService`
- Existing central template `invoice_email`
- Send goes through `InvoiceEmailService` → `CentralEmailService` (same transport as quotes/contracts)
- `finance_document_deliveries` records each send (`document_type=invoice`, channel `email`)
- Multiple sends create independent delivery rows
- Failed sends are recorded and do not change invoice financials
- Workspace isolation and Spatie / `FinanceBaseController` authorization
- `invoice_sent` audit event on success only

Not implemented: payment links, payments, receipts, reminders, recurring invoices, quote changes, public portal, WhatsApp, SMS, Inbox, AI, Flutter, POS, Booking, ZATCA rewrite, payroll, inventory, subscriptions.

## 2. Architecture used

Same pattern as Phase C quote send. No new mail transport, queue, or delivery table:

```text
Invoice show form (POST)
  → InvoiceController::send (invoices.send, workspace assert, email validation)
    → InvoiceEmailService
         → PdfInvoiceService::renderBinary (existing invoice PDF)
         → store PDF under workspaces/{id}/finance/invoices/emails/… (system path only)
         → CentralEmailService::send(template=invoice_email)
         → finance_document_deliveries row (document_type=invoice)
         → AuditLogService::log(invoice_sent)
```

Queue: `CentralEmailService` remains synchronous. Phase E does not add a job.

`invoice_status` / payment status are never set to `sent`. The existing issued-document Arabic label **مرسلة** was left as-is (pre-existing document wording, not delivery state). Delivery status is shown separately as **آخر إرسال** / **سجل الإرسال**.

## 3. Files changed

| File | Change |
| --- | --- |
| `app/Services/Finance/InvoiceEmailService.php` | Prepare + send invoice email |
| `app/Services/Finance/PdfInvoiceService.php` | `renderBinary()` shared with download |
| `app/Http/Controllers/Workspace/Finance/InvoiceController.php` | `send()` |
| `app/Models/Finance/FinanceInvoice.php` | `deliveries()`, `isSendable()` |
| `app/Models/Finance/FinanceDocumentDelivery.php` | `invoice()`, `scopeForInvoice()`, `TYPE_INVOICE` |
| `app/Enums/Finance/FinanceDocumentType.php` | Comment: invoice deliveries now persist |
| `routes/web.php` | `POST finance/invoices/{invoice}/send` |
| `resources/views/workspace/finance/invoices/show.blade.php` | Send form + delivery history |
| `database/seeders/FoundationSeeder.php` | `invoices.send` |
| `tests/Feature/Feature/Finance/FinancePhaseESendInvoiceTest.php` | Phase E coverage |

Reused without change:

- `config/email_templates.php` → `invoice_email`
- `resources/views/emails/templates/invoice-email.blade.php` (existing `_base` include)
- `finance_document_deliveries` table from Phase C

No POS, Booking, Inbox, WhatsApp, AI, Flutter, quote, ZATCA, or payment-engine files were modified.

## 4. Migrations

None. Phase C `finance_document_deliveries` already supports `document_type=invoice`.

Recorded fields used:

- `workspace_id`
- `document_type` = `invoice`
- `document_id`
- `channel` = `email`
- `recipient` (actual send address)
- `recipient_phone` (optional, not a send channel)
- `subject`
- `status` (`sending` / `sent` / `failed`)
- `provider_message_id`, `email_log_id`
- `sent_by`, `sent_at`
- `attachment_disk`, `attachment_path`
- `error`, `meta`

Previous delivery rows are never overwritten.

## 5. Services

`InvoiceEmailService`:

- Rejects draft, cancelled, deleted, non-sales, and cross-workspace customers
- Validates email server-side
- Creates a delivery row first (`sending`), then sends
- On success: `sent`, `sent_at`, provider id, `invoice_sent` audit
- On failure: `failed` + sanitized error; invoice untouched; no success audit
- Does not create Inbox `EmailMessage`
- Does not call WhatsApp or SMS
- Does not create payments, receipts, payment links, or GL entries
- Does not call `InvoiceIssueService` or change ZATCA artifacts

`PdfInvoiceService::renderBinary()` is the same HTML/PDF as the invoice download action.

`CentralEmailService` is reused. No new mail transport.

## 6. Routes

| Name | Method | Path |
| --- | --- | --- |
| `workspace.finance.invoices.send` | **POST** | `finance/invoices/{invoice}/send` |

GET is not registered. No public invoice-send URL.

## 7. Permissions

`invoices.send` added to the existing Spatie list (no new auth system).

Seeded on:

- Master permission list
- Manager role
- Accountant role
- Pro plan entitlements
- Owner/admin receive all permissions (unchanged `FinanceBaseController` elevated-member bypass)

Agent with `invoices.view` / `invoices.edit` / `invoices.issue` but without `invoices.send` receives 403.

## 8. Email template

Existing catalog key `invoice_email`:

- Config: `config/email_templates.php`
- View: `resources/views/emails/templates/invoice-email.blade.php` → `_base`

Payload lines:

- customer name
- invoice number (not internal id)
- invoice date
- total
- Arabic intro/message
- PDF attachment

Default subject: `فاتورة رقم {invoice_number}` (overridable from the form).

## 9. UI

Invoice show page (Arabic, existing Finance layout):

- Issued sales invoices: header **إرسال بالبريد الإلكتروني**
- Form: customer (read-only), email (required, overridable), phone (optional, unused for send), subject, message, attach PDF (default on)
- Draft: “يجب إصدار الفاتورة قبل إرسالها بالبريد” — submit hidden
- Cancelled: cannot send
- Purchase invoices: send not offered as customer-facing
- Delivery history: date, channel, recipient, status
- **آخر إرسال** is separate from document status (`invoice_status`) and payment status

## 10. Audit

On successful send, `AuditLogService::log`:

- action: `invoice_sent`
- entity: `FinanceInvoice`
- meta/new values: invoice id, invoice number, recipient, channel=`email`, delivery id, actor, workspace, timestamp (`occurred_at`)

Failures do not write `invoice_sent`.

## 11. Eligibility

Uses existing invoice lifecycle (`isDraft` / `isIssued` / `isCancelled`):

| Invoice | Send |
| --- | --- |
| Draft | No |
| Issued sales | Yes |
| Cancelled | No |
| Purchase | No |
| Soft-deleted | No |

## 12. Tests added

`tests/Feature/Feature/Finance/FinancePhaseESendInvoiceTest.php` (9 tests, 84 assertions):

1. Owner can send issued invoice; PDF attached; recipient correct; delivery `sent`; audit; financials unchanged; no payment/Inbox/WhatsApp
2. Unauthorized agent → 403
3. Agent with `invoices.send` can send
4. Workspace A cannot send Workspace B invoice (404)
5. Two sends → two delivery rows; override email does not change customer email
6. Failed mailer → delivery `failed`; invoice financials/GL/ZATCA snapshot unchanged; no `invoice_sent` audit
7. Draft and cancelled cannot send
8. Missing/invalid email rejected; invoice remains issued
9. Route is POST-only; issued show UI has send + history; draft has no submit; no WhatsApp copy

`CentralEmailService` is mocked (same reason as Phase C: array mailer path is unsafe in this suite).

## 13. Test results

```text
php vendor/bin/phpunit tests/Feature/Feature/Finance/FinancePhaseESendInvoiceTest.php --testdox --no-coverage
# OK (9 tests, 84 assertions)

php vendor/bin/phpunit tests/Feature/Feature/Finance tests/Unit/Finance tests/Unit/EInvoicing \
  tests/Feature/Feature/Workspace tests/Feature/Feature/Tenancy/WorkspaceIsolationTest.php \
  tests/Unit/Appointments --no-coverage
# 379 tests, 377 passed, 2 skipped, 1 risky, 1 warning, 3150 assertions, ~40.8s, exit 0
```

| Suite | Result |
| --- | --- |
| Phase E send invoice | 9 tests, passed |
| Finance feature + Finance unit + E-invoicing + Workspace + tenancy + Appointments | 379 tests, 377 passed, exit 0 |

Phase D’s comparable regression run was 370 tests / 368 passed. The +9 tests are this Phase E file. Existing Finance and ZATCA tests remain green.

## 14. Existing skipped / risky / warnings

Pre-existing, not introduced by Phase E (same as Phases C/D):

Skipped:

- `Phase7EInvoiceSecurityChainTest::test_concurrent_workers_do_not_duplicate_icv`
- `Phase7EgsConcurrencyIntegrationTest::test_parallel_workers_allocate_unique_icvs_on_server_database`

Risky:

- `Phase6UblXmlFoundationTest::test_xml_generation_does_not_query_live_business_tables`

Warning:

- `app/EInvoicing/Security/X509CertificateParser.php:21` — `openssl_x509_read(): X.509 Certificate cannot be retrieved`

## 15. Confirmations

- **Invoice financial state is untouched** by send (success or failure)
- **No GL / payment / receipt / payment-link / ZATCA changes** from sending
- **CentralEmailService reused**; no second mail stack
- **finance_document_deliveries reused**; no new delivery table
- **No POS / Booking / Inbox / WhatsApp / AI / Flutter / quote changes**
- **No public portal**
- **Duplicate delivery history is preserved** (one row per send)

## 16. Follow-ups (intentionally not implemented)

- Invoice reminders (`last_reminder_sent_at` unused here)
- Purchase-invoice email
- WhatsApp / SMS invoice send
- Inbox `EmailMessage` copies
- Payment links in the email body
- Customer portal / public accept URLs
- Queued send jobs
