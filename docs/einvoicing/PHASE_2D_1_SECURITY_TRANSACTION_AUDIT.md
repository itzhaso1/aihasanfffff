# Phase 2D.1 — Security transaction audit (reference)

See implementation report in project chat / release notes. Pre-fix finding: **SECURITY PIPELINE FAIL-OPEN** for required invoices after Phase 2D compliance gate.

**Fix (2D.1):** `ZatcaRequiredIssueGate::assertMandatoryIssuePipeline()` after `prepareFromSnapshot`, still inside `InvoiceService::issue` transaction.

**QR:** Optional on issue path (fail-open by design); security chain (ICV/PIH/hash record) mandatory for `required`.
