# FINANCE PHASE C IMPLEMENTATION REPORT

Send Quote / Email Delivery

## 1. Summary

Phase C adds email delivery for issued Finance quotes. A quote remains a sales document. Sending mail does not issue it, convert it, post GL, take payment, or enter ZATCA.

What now works:

- `quotes.send` permission
- Quote show page: **إرسال عرض السعر** form
- Issued quotes only (draft must be issued first; cancelled/deleted cannot send)
- Customer email prefills; user may edit it
- Optional phone field is stored only; it is never used for WhatsApp or SMS
- Subject / message with Arabic defaults including quote number, total, expiry, company name
- Quote PDF attached by default (`quote-{number}.pdf`) via existing `PdfQuoteService`
- `quote_email` template in the central template catalog
- Send goes through `QuoteEmailService` → `CentralEmailService` (same transport as contracts)
- `finance_document_deliveries` records each send (email channel, recipient, sent_at, status, provider id, error)
- Multiple sends create independent delivery rows
- Failed sends are recorded and do not change quote financials
- Workspace isolation and Spatie / `FinanceBaseController` authorization
- `quote_sent` audit event

Not implemented: accept/reject, public portal, viewed tracking, Quote → Invoice, payment link, receipt, WhatsApp, SMS, Inbox, AI, Flutter, POS, Booking, ZATCA changes.

## 2. Architecture used

Same pattern as contracts, without Inbox `EmailMessage` / workspace `EmailAccount` (those belong to the Email product):

```text
Quote show form (POST)
  → QuoteController::send (quotes.send, workspace assert, email validation)
    → QuoteEmailService
         → PdfQuoteService::renderBinary (Phase B PDF)
         → store PDF under workspaces/{id}/finance/quotes/emails/… (system path only)
         → CentralEmailService::send(template=quote_email)
         → finance_document_deliveries row
         → AuditLogService::log(quote_sent)
```

Queue: `CentralEmailService` is synchronous (Resend HTTP or Laravel mailer). Phase C does not add a new queue.

Document status (`draft` / `issued` / `cancelled`) is never set to `sent`. Delivery status lives only on `finance_document_deliveries`.

## 3. Files changed

| File | Change |
| --- | --- |
| `database/migrations/2026_09_10_160000_create_finance_document_deliveries_table.php` | Delivery table |
| `app/Enums/Finance/DocumentDeliveryStatus.php` | sending / sent / failed |
| `app/Enums/Finance/DocumentDeliveryChannel.php` | email |
| `app/Enums/Finance/FinanceDocumentType.php` | quote (invoice/receipt reserved) |
| `app/Enums/Finance/QuoteDeliveryStatus.php` | Comment + `unsent` for later quote-level use; not persisted on quotes |
| `app/Models/Finance/FinanceDocumentDelivery.php` | Workspace-scoped delivery model |
| `app/Models/Finance/FinanceQuote.php` | `deliveries()`, `isSendable()` |
| `app/Services/Finance/QuoteEmailService.php` | Prepare + send quote email |
| `app/Services/Finance/PdfQuoteService.php` | `renderBinary()` shared with download |
| `app/Http/Controllers/Workspace/Finance/QuoteController.php` | `send()` |
| `routes/web.php` | `POST finance/quotes/{quote}/send` |
| `config/email_templates.php` | `quote_email` |
| `resources/views/emails/templates/quote-email.blade.php` | Uses existing `_base` template |
| `resources/views/workspace/finance/quotes/show.blade.php` | Send form + delivery history |
| `database/seeders/FoundationSeeder.php` | `quotes.send` |
| `tests/Feature/Feature/Finance/FinancePhaseCSendQuoteTest.php` | Phase C coverage |
| `tests/Feature/Feature/Finance/FinancePhaseBQuotesTest.php` | Stop asserting send route is absent |

No POS, Booking, Inbox, WhatsApp, AI, Flutter, ZATCA, or invoice-send files were modified.

## 4. Migrations

`finance_document_deliveries` (additive):

- `workspace_id`
- `document_type` (`quote` now; `invoice` / `receipt` reserved, unused)
- `document_id`
- `channel` (`email`)
- `recipient`, `recipient_phone` (optional, not a send channel)
- `subject`
- `status` (`sending` / `sent` / `failed`)
- `provider_message_id`, `email_log_id`
- `sent_by`, `sent_at`
- `attachment_disk`, `attachment_path` (system-generated)
- `error`, `meta`

No quote status column was added. Quotes are not marked `sent`.

## 5. Services

`QuoteEmailService`:

- Rejects draft, cancelled, deleted, and cross-workspace customers
- Validates email server-side
- Creates a delivery row first (`sending`), then sends
- On success: `sent`, `sent_at`, provider id, audit
- On failure: `failed` + sanitized error; quote untouched
- Does not create `EmailMessage` (Inbox)
- Does not call WhatsApp or SMS

`PdfQuoteService::renderBinary()` is the same HTML/PDF as the quote download action.

`CentralEmailService` is reused. No new mail transport.

## 6. Routes

| Name | Method | Path |
| --- | --- | --- |
| `workspace.finance.quotes.send` | **POST** | `finance/quotes/{quote}/send` |

GET is not registered. Accept / reject / convert / public quote routes were not added.

## 7. UI

Quote show page (Arabic, existing Finance layout):

- Send button for issued quotes
- Form: customer (read-only), email (required), phone (optional, labeled as unused for send), subject, message, attach PDF (default on)
- Clear empty-email hint: لا يوجد بريد إلكتروني للعميل
- Delivery history: date, channel, recipient, status
- Copy states that document status and delivery status are separate
- Draft: “يجب إصدار عرض السعر قبل إرساله بالبريد”
- Cancelled: cannot send

Success flash: `تم إرسال عرض السعر إلى {email}`

## 8. Email template

Catalog key `quote_email` → `emails.templates.quote-email` → existing `emails.templates._base`.

Payload includes:

- Company name (`brand_name`)
- Customer name
- Quote number
- Issue date
- Expiry date
- Total
- User message (`intro`)
- Footer stating this is a quote, not a tax invoice

No public quote URL. No action button.

Default subject: `عرض سعر رقم {quote_number}`

## 9. PDF attachment

Generated by `PdfQuoteService::renderBinary()` (same as user download).

Filename: `quote-{quote_number}.pdf`

Stored only by the server under `workspaces/{workspace_id}/finance/quotes/emails/{uuid}_{filename}`. The user cannot supply an attachment path.

## 10. Delivery records

Each POST creates a new `finance_document_deliveries` row. Re-send does not overwrite.

Statuses: `sending` → `sent` or `failed`.

Quote `status` stays `issued`.

`viewed` is not recorded.

Invoice/receipt send is not implemented; the table can hold those document types later.

## 11. Permissions

`quotes.send` added to the existing Spatie list (no new auth system).

- Agent with `quotes.send` can send
- Agent without it gets 403
- Owner/admin/manager still pass `FinanceBaseController` elevated-member bypass (same as invoices)
- Manager and accountant roles include `quotes.send`
- Pro plan entitlements include `quotes.send`

## 12. Audit log

On successful send, `AuditLogService::log`:

- action: `quote_sent`
- entity: `FinanceQuote`
- meta/new values: quote id, quote number, recipient, channel=`email`, delivery id, actor, workspace, timestamp (`occurred_at`)

Failures do not write `quote_sent`.

## 13. Tests added

`tests/Feature/Feature/Finance/FinancePhaseCSendQuoteTest.php` (9 tests):

1. Owner can send; PDF attached from the correct quote; subject/customer/number/total in payload; delivery sent with sent_at; audit; no invoice/payment/GL/ZATCA snapshot/Inbox/WhatsApp
2. Agent without `quotes.send` → 403
3. Agent with `quotes.send` can send
4. Workspace A cannot send Workspace B quote (404)
5. Missing email and invalid email are rejected
6. Two sends → two delivery rows
7. Failed mailer → delivery `failed`, quote financials unchanged, no `quote_sent` audit
8. Draft and cancelled cannot send
9. Route is POST-only; show UI has send + history; no accept/reject/WhatsApp copy

`CentralEmailService` is mocked in tests because the array-mailer path is already skipped as process-crashing in `CentralEmailServiceTest`.

## 14. Tests updated

`FinancePhaseBQuotesTest`: removed `assertFalse(Route::has('workspace.finance.quotes.send'))` so Phase B still asserts convert/accept are absent, not send.

## 15. Full test results

```text
php vendor/bin/phpunit tests/Feature/Feature/Finance/FinancePhaseCSendQuoteTest.php \
  tests/Feature/Feature/Finance/FinancePhaseBQuotesTest.php --testdox
# OK (18 tests, 173 assertions)

php vendor/bin/phpunit tests/Feature/Feature/Finance tests/Unit/Finance tests/Unit/EInvoicing \
  tests/Feature/Feature/Workspace tests/Feature/Feature/Tenancy/WorkspaceIsolationTest.php \
  tests/Unit/Appointments --no-coverage
# 357 tests, 355 passed, 2 skipped, 1 risky, 1 warning, 2882 assertions, ~34.5s, exit 0
```

| Suite | Result |
| --- | --- |
| Phase C send quote | 9 tests, passed |
| Phase B quotes | 9 tests, passed |
| Finance feature + Finance unit + E-invoicing + Workspace + tenancy + Appointments | 357 tests, 355 passed, exit 0 |

Phase A foundation tests live under `tests/Feature/Feature/Finance` and passed in the combined run.

## 16. Existing skipped/risky tests

Pre-existing, not introduced by Phase C:

Skipped:

- `Phase7EInvoiceSecurityChainTest::test_concurrent_workers_do_not_duplicate_icv`
- `Phase7EgsConcurrencyIntegrationTest::test_parallel_workers_allocate_unique_icvs_on_server_database`

Risky:

- `Phase6UblXmlFoundationTest::test_xml_generation_does_not_query_live_business_tables`

Warning:

- `app/EInvoicing/Security/X509CertificateParser.php:21 openssl_x509_read(): X.509 Certificate cannot be retrieved`

## 17. Failures and fixes

None after implementation. Combined suite exit code 0.

## 18. Security considerations

- Quote route model binding is workspace-scoped; cross-workspace send is 404
- Customer is re-loaded by `workspace_id` + `customer_id`
- Email validated with Laravel `email:filter` plus service-side `FILTER_VALIDATE_EMAIL`
- Attachment path is generated by the server; request cannot set a file path
- Template data is escaped through the existing Blade `_base` (`e()` / `{{ }}`)
- Failure messages strip API keys / secrets / stack traces
- Phone is never passed to WhatsApp or SMS services
- No Inbox `EmailMessage` is created
- No public quote token or URL

## 19. Follow-up for Phase D

Not in this PR:

- Customer accept / reject
- Public / tokenized quote portal
- Viewed tracking
- Quote → Invoice
- Payment link / receipt
- WhatsApp / SMS send
- Invoice email send (table is ready; behavior is not)

Delivery vs document status should stay separate when those features land.

## Confirmations — not implemented

| Item | Status |
| --- | --- |
| Quote acceptance | Not implemented |
| Quote rejection | Not implemented |
| Public portal | Not implemented |
| Viewed tracking | Not implemented |
| Quote → Invoice | Not implemented |
| Payment link | Not implemented |
| Receipt | Not implemented |
| WhatsApp | Not implemented / not called |
| SMS | Not implemented |
| Inbox | Not called (`EmailMessage` count stays 0) |
| AI | Not changed |
| Flutter | Not changed |
| POS | Not changed |
| Booking | Not changed |
| ZATCA architecture | Not changed |

## Success criteria

- [x] `quotes.send` permission exists
- [x] Unauthorized user cannot send
- [x] Quote email can be sent
- [x] Customer email is validated
- [x] Quote PDF is attached
- [x] Correct Quote PDF is attached
- [x] Email template works
- [x] Quote number appears in email
- [x] Customer name appears
- [x] Total appears
- [x] Delivery record is stored
- [x] Delivery status is separate from Quote status
- [x] `sent_at` is stored
- [x] Multiple sends create independent records
- [x] Failed delivery does not modify Quote financial data
- [x] Workspace isolation works
- [x] Audit event is recorded
- [x] No WhatsApp / SMS / Inbox / AI / Payment / Receipt / Quote → Invoice / Customer portal / ZATCA changes
- [x] Existing Finance and Phase A/B tests pass
