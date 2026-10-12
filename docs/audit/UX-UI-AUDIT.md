# UX-UI-AUDIT

Audit date: 2026-09-13  
First-time-user review from information architecture, not a full click-through.

---

## 1. Confusing by design (evidence)

| Confusion | Why |
| --- | --- |
| Three apps + Blade for the same company | User does not know which icon is “حاسم” |
| Finance exists twice (web + Flutter) | Sidebar parity was already a product fight |
| Channels show Instagram/Messenger | Settings `connected` flag ≠ working pipes |
| Copilot looks like AI | Keyword SQL |
| Dashboard “كم ربحنا؟” vs ledger P&L | Documented divergence |
| Plans vs merchant KYC | Paying SaaS ≠ allowed to collect GMV (`docs/product.md`) |
| Accountant invite becomes member | Enum mapping |
| Website/appointments removed | Old bookmarks / docs / tests |
| Legal pages with brackets | Unprofessional + non-compliant feel |
| Public QR menu | Anyone with slug can order food |
| POS offline vs “cloud invoice” | Cashier may think sale is final before sync |

---

## 2. Onboarding

Open registration → workspace → plan. No activity-type gate. Good for speed; weak for KYC education.

Missing: guided first invoice, WhatsApp connect checklist, “you cannot collect card payments until verified.”

---

## 3. Destructive actions

Finance cancel/reverse/delete exist on API. UNKNOWN whether every Blade/Flutter screen has confirm + reason.

---

## 4. Theme

Finance Flutter dark mode implemented in code. Blade/cashier/chat dark: UNKNOWN / PARTIAL.

---

## 5. Finding

### AUD-UX-001
- Severity: Medium
- Title: Product vocabulary and surfaces are not one product in the user’s head
- Impact: Support load, mis-posting, abandoned onboarding
- Recommendation: One workspace home that launches modules with honest status badges
- Priority: P2
