# Product Overview

Honest status of the HASem SaaS productization layer as of the current codebase.

## Registration

Open registration: name, email/phone, password → workspace → choose plan.  
Legal activity type (Individual / Company / Freelancer) is **not** required.  
Legacy `workspaces.type` defaults to `company` for compatibility only — it does **not** control payment eligibility.

## Official plans (user-facing)

Only four: **Starter / Pro / Business / Enterprise** (`starter`, `pro`, `business`, `enterprise`).  
Arabic: المبتدئة / الاحترافية / الأعمال / المؤسسات.  
Legacy plan rows may exist hidden (`is_official=false`, `is_public=false`).

## Merchant payments vs platform billing

Three money contexts — see `docs/billing.md` and `docs/merchant-payments.md`.

Receiving **customer money** requires Merchant Verification + Provider Onboarding — not just a Pro plan.

## What is production-ready enough to use

- **Workspace tenancy** with membership roles and workspace-scoped models.
- **POS / cashier** tables, kitchen, invoices, QR menus (existing core — do not regress).
- **Finance** invoices, expenses, payroll modules (existing core — do not regress).
- **Feature entitlements** matrix (`plans` table + `FeatureAccessService`) with meters and overrides.
- **Merchant verification** queue (Platform Admin) + workspace payment eligibility UI.
- **CRM light**: customer tags, groups, notes.
- **WhatsApp outbound foundation** (needs Meta tokens to actually send).
- **Analytics MVP**: workspace-scoped revenue / orders / customers / top products.
- **API Keys foundation**: create / list / revoke / regenerate; hash-only storage; plaintext shown once.
- **AI assistant** (public + member) with hardened prompts and no cross-workspace dumps.

## What is intentionally incomplete / placeholder

- **Paid subscription gateways** (Stripe Billing / HyperPay charge): interface exists; runtime provider is `LocalSubscriptionBillingProvider`.
- **HyperPay marketplace / split merchant settlement**: abstraction + pending provider only — **not live**.
- **WhatsApp Cloud API**: UI + webhook routes exist; real send requires `WHATSAPP_*` tokens.
- **Public API consumption of ApiKey**: foundation-stage.
- **Add-on purchase checkout**: models/grants exist; no live fake billing checkout.
- **Usage metering**: AI + WhatsApp wired; other meters schema-ready but not all consumers increment yet.

## Module map (high level)

| Area | Status |
|------|--------|
| POS | Core solid |
| Finance | Core solid |
| Plans / entitlements | Official 4-plan catalog |
| Merchant verification | Implemented (docs private) |
| Merchant HyperPay split | Not live |
| Analytics | MVP dashboards |
| API keys | Product foundation |
| Billing (platform) | Local provider |
| WhatsApp | Token-gated |
| Docs | This folder |

## Non-goals for this pass

Do not break POS / Finance cores while productizing billing & merchant eligibility.
