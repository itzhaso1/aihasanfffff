# FRONTEND-AUDIT

Audit date: 2026-09-13  
Includes Blade web + notes on Flutter. Visual runtime was **not** re-walked in this audit (UNKNOWN for broken pixels).

---

## 1. Inventory

| Surface | Files | Stack |
| --- | --- | --- |
| Blade | 317 `*.blade.php` | Laravel + Alpine + Tailwind/Vite |
| Hasim Chat | 84 dart lib files | Riverpod, go_router |
| Hasim Finance | 49 dart lib files | Shared tokens `FinancePalette` |
| Hasim Cashier | 116 dart lib files | Dedicated Hasim color/spacing/radius |

No React/Vue SPA in the monolith (Vite builds CSS/JS for Blade).

---

## 2. Design system

| Token area | Blade | Finance Flutter | Cashier Flutter | Chat Flutter |
| --- | --- | --- | --- | --- |
| Colors | Mixed Tailwind + finance-shell CSS | `finance_tokens.dart` | `hasim_colors.dart` | mixed |
| Fonts | page-level | google_fonts | hasim_typography | google_fonts |
| Spacing / radius | ad hoc + finance shell | tokens | hasim_spacing / radius | ad hoc |
| Buttons / inputs / cards | duplicated per module | shared widgets | hasim_widgets | hasim_widgets |
| Theme light/dark | finance shell styled; system theme UNKNOWN on Blade | Light/Dark/System implemented | cashier theme files | UNKNOWN completeness |

**Inconsistencies:** three visual languages (communication layout, financial layout, POS layout) plus three Flutter apps. Dual finance sidebars (Blade vs Flutter) already drifted in product history.

---

## 3. Dark / light (static)

| Surface | Light | Dark | System |
| --- | --- | --- | --- |
| Finance Flutter | PASS (code present) | PASS (tokens + ThemeData) | PASS (`themeModeProvider`) |
| Blade finance | PARTIAL | UNKNOWN / likely BROKEN | UNKNOWN |
| Blade inbox/POS | PARTIAL | UNKNOWN | UNKNOWN |
| Cashier | PARTIAL | UNKNOWN without runtime | UNKNOWN |
| Chat | PARTIAL | UNKNOWN | UNKNOWN |

Contrast, charts, dropdowns: UNKNOWN — Requires runtime verification.

---

## 4. UX / UI defects (code-derived)

| Issue | Status | Evidence |
| --- | --- | --- |
| Dual finance UI | High debt | Blade + Flutter |
| Channel “connected” without backend | Dead / misleading UI | Instagram/Messenger cards |
| Legal placeholders | Incomplete | `[البريد الإلكتروني الرسمي]` |
| Website builder removed | Dead nav risk | retirement migration 2026-09-12 |
| Hardcoded demo risk | Tests forbid `Foreign Giant` on dashboard | leftover sample data possible |
| Missing export | Reports hub “export not started” (status doc; ExportController exists on API — verify UI) | Partial |
| Loading / empty / error | Chat has `empty_state`, `async_body`; Blade mixed | Partial |
| Responsiveness | Finance tests exist for dashboard; cashier table UX tests | Partial |

---

## 5. Findings

### AUD-FE-001
- Severity: Medium
- Title: Three design systems, no shared package
- Location: `resources/views/layouts/*`, `apps/*/lib/core/theme`
- Impact: Inconsistent product; slower QA
- Recommendation: Do not merge apps; share tokens doc only
- Effort: L
- Priority: P3

### AUD-FE-002
- Severity: Medium
- Title: Blade finance dark mode not evidenced
- Location: `finance-shell-styles.blade.php`
- Impact: System dark OS → unreadable if CSS is light-only
- Recommendation: Runtime pass
- Effort: M
- Priority: P2

UNKNOWN — Requires browser verification of every Blade module (this audit did not click the app).
