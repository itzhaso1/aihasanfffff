# Merchant Verification

## Purpose

Any Workspace may register and use the SaaS.  
Receiving **customer money** requires merchant verification + provider onboarding.

## Statuses

`not_requested` → `documents_required` / `pending_review` → `approved` | `rejected` | `suspended`

## Documents

- Stored on private disk (`local` → `storage/app/private`)
- Types managed in `merchant_document_types` (seeded: freelance certificate, commercial registration, other)
- Admin download via authorized Platform routes (not public URLs)

## Flows

Workspace: Payments → تفعيل استقبال المدفوعات  
Platform: Merchant Verification queue (approve / reject / suspend / request docs)

All transitions audit-logged via `AuditLogService`.
