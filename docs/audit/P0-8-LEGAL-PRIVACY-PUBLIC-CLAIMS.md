# P0-8 FINAL

**Date:** 2026-09-13  
**P0-1 … P0-7:** CLOSED — not reopened.  
**Scope:** Public legal pages, `config/legal.php`, privacy disclosures, public compliance claims only. No Finance / POS / tenancy / runtime / business-logic changes.

## Status

**CLOSED**

Public legal pages no longer present bracket placeholders as company facts. Confirmed operator facts (name, phone, CR, Riyadh address) are the only legal identity values rendered. Unknown fields stay `null` and do not render. `/refund` is unpublished (404). Public marketing no longer shows a ZATCA / SAMA accreditation block. Signup links Terms + Privacy without claiming a stored policy-version record. SQLite legal + auth regression: **37 tests, 328 assertions, PASS**.

P1 / P2 were not started.

---

## Legal Pages

| Page | Route | Public? | Strategy | Status |
| --- | --- | --- | --- | --- |
| Privacy | `GET /privacy` `legal.privacy` | Yes | **A — Complete** from proven product behavior + confirmed company facts | Published, conservative |
| Terms | `GET /terms` `legal.terms` | Yes | **A — Complete** without invented SLA / trial / refund / governing law | Published, conservative |
| Cookies | `GET /cookies` `legal.cookies` | Yes | **A — Complete** for essential session / CSRF / remember / `pos_guest_` only | Published |
| Acceptable Use | `GET /acceptable-use` `legal.acceptable-use` | Yes | **A — Complete** from product abuse surface | Published |
| Refund | `GET /refund` `legal.refund` | **No** | **B — Unpublish** — no owner-approved refund policy exists | **404** |

Per-page checklist (A–I):

| Check | Privacy | Terms | Cookies | Acceptable Use | Refund |
| --- | --- | --- | --- | --- | --- |
| A Public | Yes | Yes | Yes | Yes | No (404) |
| B Placeholders | Removed | Removed | Removed | Removed | Not published |
| C Unproven facts | Stripped / qualified | Stripped / qualified | Matches cookie usage | Product-aligned | n/a |
| D Effective date | Not shown (null) | Not shown (null) | Not shown (null) | Not shown (null) | n/a |
| E Contact | Phone + CR + address only | Same | Same | Same | n/a |
| F Retention / deletion | No fixed period; account delete via profile + password is described | n/a | n/a | n/a | n/a |
| G Cookie / consent semantics | Points to cookies page | n/a | Essential only; no banner (none needed) | n/a | n/a |
| H Link works | 200 | 200 | 200 | 200 | 404 by design |
| I Matches product | Conservative yes | Conservative yes | Proven cookie names | Yes | Unpublished |

---

## Inventory (audit before code)

| Document / Claim | Current state (before) | Public? | Complete? | Contains placeholder? | Risk | Action |
| --- | --- | --- | --- | --- | --- | --- |
| `config/legal.php` | Mix of confirmed facts + bracket-style unknowns | Via views | No | Yes if rendered | High — fake email / tax / dates | Null unknowns; guard render |
| `/privacy` | Public draft with `[البريد]`, `[تاريخ السريان]`, `[مدة الاحتفاظ]` | Yes | No | Yes | High | Option A: rewrite to proven processing only |
| `/terms` | Public draft with jurisdiction / refund / trial slots | Yes | No | Yes | High | Option A: remove invented promises |
| `/cookies` | Public; session/CSRF/`pos_guest_` already accurate | Yes | Mostly | Date/email slots | Medium | Option A: drop placeholders |
| `/acceptable-use` | Public, mostly product-aligned | Yes | Mostly | Date/contact slots | Medium | Option A: drop placeholders |
| `/refund` | Missing route + no approved policy | Intended yes, not real | No | Would be invented | High | Option B: route exists, **unpublished** |
| Footer «الاعتمادات والربط الرسمي» | ZATCA + SAMA badges + «متوافقة مع متطلبات السوق السعودي» | Yes | False claim | n/a | **Critical** | Remove accreditation block |
| Footer payment marks | VISA / PayPal / HyperPay as «وسائل الدفع المعتمدة» | Yes | Overclaim | n/a | High | Re-label as marks that may appear |
| Signup consent | Links Terms + Privacy; «بإنشاء الحساب فإنك توافق» | Yes | Notice only | No | Medium if we claimed a log | Keep notice; do **not** invent acceptance records |
| Cookie banner | None | n/a | n/a | n/a | Low | Do not add fake banner (essential cookies only) |
| Landing / downloads SEO | No ZATCA / PDPL / VAT certification strings | Yes | n/a | No | Low | Leave; scan covered |
| Workspace invoice «ZATCA: غير مهيأة» | Accurate harness status | Auth workspace, not legal page | Honest | No | SAFE | **Unchanged** (out of P0-8 mutation scope) |
| Internal `docs/` ZATCA text | Says **not** certified / foundation only | No | Honest | No | SAFE | Not public marketing |
| EN legal pages | Do not exist | No | No | n/a | REVIEW | Do not invent translations |

---

## Before

- Legal layout always printed `آخر تحديث: [تاريخ السريان]`.
- Contact partial always printed `[البريد الإلكتروني الرسمي]`.
- Privacy / terms / cookies / acceptable-use treated bracket tokens as if they were legal facts.
- Old `LegalPagesTest` **required those placeholders to be visible**.
- Public footer claimed official accreditation (ZATCA + SAMA) and Saudi-market “compliance” wording.
- No `/refund` policy existed, but the product looked as if a refund policy slot was a real term.
- Unknown `config/legal.php` fields could leak as production facts.

---

## Changes

- `config/legal.php`: confirmed company fields only; `email`, `effective_date`, `tax_number`, `retention_period`, `jurisdiction`, `refund_policy`, `trial_period` = `null`; `pages.refund` = `false`.
- `LegalPageController`: published pages gated by `legal.pages.{view}`; `refund()` 404 unless published.
- `routes/web.php`: `GET /refund` → `legal.refund` (404 while unpublished). No refund Blade view created.
- `layouts/legal.blade.php`: “آخر تحديث” only if `effective_date` is filled.
- `legal/partials/contact.blade.php`: email row only if `email` is filled.
- Privacy / terms / cookies / acceptable-use: placeholders removed; wording limited to proven behavior (no retention days, no at-rest encryption claim, no PDPL claim, no region lock, no backup promise, no invented jurisdiction / refund / trial).
- Unused `.hs-legal-placeholder` CSS removed so the class name cannot appear in public HTML.
- Cookies analytics section does not name third-party trackers (avoids implying they are installed).
- `company-footer-content.blade.php`: ZATCA / SAMA accreditation column removed; payment heading is «علامات دفع قد تظهر في الموقع» with a disclaimer that logos are not certificates; tagline is «منصة تشغيل أعمال موجّهة للسوق السعودي».
- `tests/Feature/LegalPagesTest.php`: rewritten to assert 200/404, confirmed facts, no placeholders / invented emails / TODO / certification claims, footer + signup + downloads links, production env still clean.

Finance, POS, tenancy, and webhook code were not modified.

---

## Legal Facts Used

Only operator-confirmed / already-documented values:

- Company: **حاسم الذكي**
- Phone: **+966 59 539 7059**
- Commercial registration: **7054911743**
- Address: **الرياض، حي المونسية، طريق الأمير محمد بن سلمان بن عبدالعزيز**

No official legal email, VAT / tax number, DPO, refund address, governing law, jurisdiction, retention period, trial length, or effective date was added.

---

## Placeholder / Missing Facts

These stay `null` / unpublished / undocumented as public facts:

- Official legal email
- VAT / tax ID
- DPO contact
- Effective date / document version IDs
- Retention period (no job or policy implements a fixed X days / years)
- Governing law / jurisdiction
- Refund / settlement policy
- Trial period and renewal / notice periods
- SLA / uptime guarantee
- Stored Terms / Privacy acceptance timestamp + version (registration does not persist this)
- English legal translations
- “Encrypted at rest”, “data never leaves region”, “fully PDPL compliant”

If a required legal value is still unknown, it is omitted — not guessed.

---

## Public Claims Scan

Repository-wide search for: `ZATCA certified`, `ZATCA approved`, `ZATCA compliant`, `certified by ZATCA`, `PDPL compliant`, `Saudi certified`, `tax certified`, `VAT certified`, `e-invoice compliant`, plus Arabic «معتمدون من هيئة الزكاة» / «متوافقون مع PDPL».

| Family | Classification | Notes |
| --- | --- | --- |
| **ZATCA** (public marketing / legal / landing / signup / downloads) | **REMOVE** (done) then **SAFE** | Footer accreditation block removed. No public “certified / approved / compliant” string remains. |
| **ZATCA** (workspace finance UI / PDF) | **SAFE** | «الفوترة الإلكترونية ZATCA: غير مهيأة» / «لا يفعّل الربط» — accurate harness status, not a public certificate. Left unchanged. |
| **ZATCA** (internal `docs/`, e-invoicing harness, tests) | **SAFE** | Docs and tests state foundation / **not** certified. Not customer-facing legal pages. |
| **PDPL** | **SAFE** | No public “PDPL compliant” claim. Privacy does not assert a named statutory compliance badge. |
| **VAT** | **SAFE** | No “VAT certified” / “Saudi tax certified” public claim. Customer VAT fields may exist inside tenant data; that is not a platform certification. |
| **Other** | **QUALIFY** | Payment brand marks may appear; footer now says they are not certificates. Landing CTA «ابدأ مجانًا» is a button label, not a stated trial length (Terms refuse to invent one). |

No new e-invoicing marketing slogan was added. Existing capability remains an integration **foundation / harness**, not clearance.

---

## Signup / Consent

- Register copy: creating an account means agreement with Terms + Privacy; both links resolve to published routes.
- Login footer links the same two documents.
- `RegisteredUserController` validates name / email / phone / password / workspace name only. **No** `terms_accepted_at`, document version, or policy hash is stored.
- Public text does **not** claim that a policy version is recorded.

---

## Cookies / Consent

| Category | Found on public site? |
| --- | --- |
| Essential | **Yes** — Laravel session, CSRF / XSRF, optional remember-me, `pos_guest_*` for public QR / table guest |
| Analytics (marketing pixels) | **Not found** on the public surface |
| Marketing cookies | **Not found** |
| Cookie banner | **None** — not added (would be cosmetic only) |
| Consent storage | **None** — not claimed |

Third-party cookies may appear on **their** domains if the user uses Google/Facebook login or HyperPay/Stripe checkout. That is disclosed without inventing extra trackers.

---

## Data rights (disclosure vs implementation)

| Right | Implemented? | Public wording |
| --- | --- | --- |
| Profile correction | Yes (profile / workspace forms) | Described as available in-product |
| Account deletion | Yes — `ProfileController::destroy` + current password | Described; no promise that every workspace/invoice/chat row is purged |
| Data export | No dedicated legal-export flow found | Not promised as a button/right |
| Formal deletion request workflow / legal hold | Not a product feature | Contact via phone/CR/address only; no invented DPO email |
| Fixed retention / regional residency / at-rest encryption | Not proven | Not claimed |

---

## Arabic / English

- Published legal pages are **Arabic only**.
- No English legal translations were authored (that would be invented legal text).
- The product does **not** claim EN/AR legal parity.
- **REVIEW:** owner may commission official bilingual counsel text later. Until then, do not advertise English policies.

---

## Tests

Command:

```text
php artisan test --testdox --filter="LegalPages|AuthenticationTest|RegistrationTest|PlatformAdminAuth|MobileAuthAndWorkspace|FailClosedWorkspaceTenancy"
```

Runtime: PHP 8.3.33, PHPUnit 12.5.35, SQLite (phpunit.xml). No MySQL invented for legal pages.

| | |
| --- | --- |
| **PASS** | 37 tests, 328 assertions |
| **FAIL** | 0 |
| **SKIPPED** | 0 |
| **ERRORS** | 0 |

Coverage vs requested cases:

1. `/privacy` 200 — PASS  
2. `/terms` 200 — PASS  
3. `/cookies` 200 — PASS  
4. `/acceptable-use` 200 — PASS  
5. `/refund` 404 while unpublished — PASS  
6. No unapproved public placeholders — PASS  
7. No fake email / tax ID — PASS  
8. No ZATCA certified/approved/compliant — PASS  
9. No unproven PDPL compliance claim — PASS  
10. Footer legal links resolve; refund link absent — PASS  
11. Signup legal links resolve — PASS  
12. Production env does not expose TODO / bracket placeholders / `hs-legal-placeholder` — PASS  

Auth / tenancy regression included in the same run: Authentication, Registration, PlatformAdminAuth, MobileAuthAndWorkspace, FailClosedWorkspaceTenancy — all PASS.

---

## Public URLs / Routes

| URL | Name | Production behavior |
| --- | --- | --- |
| `/privacy` | `legal.privacy` | 200 |
| `/terms` | `legal.terms` | 200 |
| `/cookies` | `legal.cookies` | 200 |
| `/acceptable-use` | `legal.acceptable-use` | 200 |
| `/refund` | `legal.refund` | **404** (`legal.pages.refund` = false) |
| `/` landing footer | — | Links the four published pages only |
| `/downloads` footer | `downloads` | Same four links |
| `/register` | `register` | Terms + Privacy |
| `/login` | `login` | Terms + Privacy |

Linked from: `partials/public-site-footer.blade.php`, `partials/company-footer-content.blade.php` (when `showLinks`), register, login.

---

## Remaining Review

Owner / counsel items — **do not invent** in product copy until provided:

1. Official legal email and optional DPO contact.  
2. VAT / tax number if it must appear on public legal pages.  
3. Adopted effective date + document versioning (and, if desired, stored signup acceptance).  
4. Retention schedule that matches real jobs / backups.  
5. Governing law / jurisdiction.  
6. Approved refund policy — only then set `legal.pages.refund` = true **and** add a real view.  
7. English legal translations by qualified counsel.  
8. Flutter local-storage / offline-cache privacy addendum if those clients persist PII (not described as a public claim today).  
9. If a marketing analytics pixel is added later, update `/cookies` **before** any “we use X” or banner claim.

These gaps are omitted or unpublished. They are **not** filled with guessed values. They do not reopen P0-1 … P0-7 and do not start P1 / P2.
