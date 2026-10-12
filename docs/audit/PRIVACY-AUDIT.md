# PRIVACY-AUDIT

Audit date: 2026-09-13

---

## 1. Legal pages

| Document | Route | Status |
| --- | --- | --- |
| Privacy | `/privacy` | Incomplete — placeholders |
| Terms | `/terms` | Incomplete |
| Cookies | `/cookies` | Incomplete |
| Acceptable Use | `/acceptable-use` | Incomplete |
| Refund | placeholder in `config/legal.php` | Missing as policy |
| Consent / cookie banner | not evidenced | Missing / UNKNOWN |

`config/legal.php` placeholders: official email, effective date, tax number, retention, jurisdiction, refund, trial length.  
Company name, phone, CR, Riyadh address **are** filled.

Classification: **Needs legal review before public launch.** Not “present and complete.”

---

## 2. Data classes

| Class | Stored where | Notes |
| --- | --- | --- |
| PII (name, phone, email) | `users`, `customers`, email contacts | required for product |
| Financial | invoices, payments, journals, payroll | high sensitivity |
| Chat | messages, attachments, WhatsApp | high sensitivity |
| KYC documents | private disk | good |
| AI logs | full prompts | **excessive** |
| Email logs | recipient/subject/error, unscoped model | risk |
| Device tokens | `device_push_tokens` unscoped | risk |
| Analytics | workspace analytics service | MVP |
| Local Flutter DBs | chat + cashier devices | high if stolen |
| Backups | cashier encrypted local; Laravel UNKNOWN | |

---

## 3. Findings

### AUD-PRV-001
- Severity: High
- Title: Legal facts incomplete while pages are public
- Location: `config/legal.php`, `LegalPageController`
- Impact: Regulatory / trust failure in KSA
- Priority: P0

### AUD-PRV-002
- Severity: High
- Title: AI and email logs retain unnecessary content
- Location: `AiLog`, `EmailLog`
- Impact: Over-retention
- Priority: P1

### AUD-PRV-003
- Severity: Medium
- Title: No documented retention/deletion job
- Location: no GDPR/PDPL purge command found
- Impact: Cannot honor deletion requests
- Priority: P1

Logging of passwords/tokens: repair notes say queue fail does not log payloads; OTP not returned. UNKNOWN all `Log::` call sites.
