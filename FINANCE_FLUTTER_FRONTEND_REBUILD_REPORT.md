# Finance Flutter frontend rebuild report

Product: **HASEM Finance** (`apps/hasim_finance` client of Laravel Finance).  
Branch: `cursor/finance-flutter-frontend-rebuild-9bf7`  
Reference: attached Arabic RTL SaaS screenshot (right sidebar, top header, dense KPI cards).

This is a Finance product rebuild. Company people / salaries / advances are **financial obligations of the workspace company**, not HASEM HR and not HASEM staff payroll.

---

## 1. Existing frontend problems

Before this rebuild, Finance Flutter already had Riverpod + go_router + Dio, Arabic locale, and broad module coverage, but the UI still read as a generic Flutter template:

- Default Material `AppBar` stacked under a simple shell, producing double headers.
- Inconsistent cards, spacing, and typography across dashboard, hubs, lists, and forms.
- Dashboard KPIs and charts did not follow the reference hierarchy (hero / secondary / tertiary + table + mix chart).
- No centralized design tokens; screens styled locally.
- RTL sidebar/header language was incomplete relative to the reference (right sidebar + compact top chrome).
- **Company people financial obligations were missing** from the Flutter client even though Laravel Web already had `FinanceEmployee`, payroll records, salary advances, and payroll adjustments.
- `/api/finance/v1` did not expose those Web capabilities to the client.

---

## 2. Reference image analysis

The attached screenshot defines the visual language:

| Area | Reference behavior |
|---|---|
| Shell | Light canvas, **right sidebar**, white top header, content to the left of the sidebar |
| Sidebar | Brand block, grouped Arabic nav, teal selected pill, compact 13px labels |
| Header | Pill search, notification/settings icon buttons, user chip |
| Page header | Large title on the start side (RTL right), primary action opposite |
| Filters | One compact white card: dates + customer + apply |
| KPIs | Rounded white cards, soft shadow, icon badge, large number + `ر.س`, optional delta |
| Density | Multi-row KPI grid, then charts + compact invoice table |
| Charts | Grouped bars (sales vs expenses) and donut mix |
| Color | Teal brand, light gray canvas `#F3F6F8`, subtle borders, status greens/reds |

Laravel remains the source of truth for fields and numbers. The screenshot does not authorize dropping invoice/tax/ZATCA/payroll fields that exist in Laravel.

---

## 3. Design system

Centralized in:

- `apps/hasim_finance/lib/core/theme/finance_tokens.dart` — color, radius, spacing, shadows, sidebar width
- `apps/hasim_finance/lib/core/theme/app_theme.dart` — Material 3 theme wired to tokens (Cairo, inputs, buttons, tables, chips, dialogs)
- `apps/hasim_finance/lib/core/layout/finance_chrome.dart` — `FinanceScaffold`, page header, filter bar, surface, icon badge
- `apps/hasim_finance/lib/core/layout/kpi_card.dart` — KPI card + responsive grid
- `apps/hasim_finance/lib/core/layout/finance_charts.dart` — bar + donut painters (no second charting engine)
- `apps/hasim_finance/lib/core/layout/finance_layout.dart` — page container, form section/grid

Screens must not invent local palettes. Status chips, money text, and form sections share the same language.

---

## 4. Shell redesign

`FinanceShell` now matches the reference:

- **Desktop (≥980px):** `Row` with sidebar first so RTL places it on the **right**, then header + content.
- **Sidebar:** Finance brand mark, workspace name, grouped Arabic navigation, teal selected background.
- **Header:** global search, alerts, settings, user/workspace chip.
- **Mobile:** compact header (search icon + avatar), bottom navigation + “more” sheet.
- New nav group **الموظفون والمستحقات**: people, payroll, advances, allowances, bonuses, deductions.

Auth screens (login, forgot password, workspace gate) stay outside this shell and keep Sanctum / Google login unchanged.

---

## 5. Dashboard redesign

`DashboardScreen` now uses real `FinanceApi.dashboard()` cards and analytics:

- Hero KPIs from Laravel `analytics.hero` when present (fallback: sales / receivables / payables)
- Secondary: receivables, sales, purchases, expenses
- Tertiary: net profit, **output VAT**, invoices due, overdue invoices, cash balance
- Payroll row when `payroll.view` is granted (company employee count, payroll paid, open advances, deductions) — Laravel totals, not Flutter math
- Bottom row: sales vs expenses bars, recent/overdue invoices table, sales mix donut
- Attention items and recent expenses preserved
- Filters: from/to, customer, product, project, lifecycle, payment method, apply, reset

Flutter does not recompute financial totals.

---

## 6. Invoice redesign

Invoice list/detail/compose keep Laravel fields (customer / walk-in, tax subtype, ZATCA requirement, contract, project, dates, currency, payment terms, tax profile/mode/rate, lines, notes). Compose now uses `FinanceScaffold` + grouped `FormSection`s instead of a generic `AppBar`. Line totals are still not calculated in Flutter; payloads omit client-side line totals as before.

---

## 7. Finance modules redesigned

Shared chrome applied across genuine Finance screens already present in Laravel:

- Lists: `PagedListScreen` + filter bar + `FinanceScaffold`
- Hubs: sales, billing, VAT, accounting, treasury, alerts, copilot, exports — `AppBar` replaced with `FinanceScaffold`
- Forms: invoices, quotes, customers, suppliers, expenses, purchases, contracts, notes, projects, price lists, purchase orders, leads, settings, reports
- Catalog and statements keep existing API-backed fields

POS, Booking, Inbox, WhatsApp, Instagram, and Messenger were not merged in.

---

## 8. People financial obligations implementation

Laravel Web already modeled **workspace company people** (`FinanceEmployee`), independent of HASEM users.

Flutter now exposes:

- List: `/people` — person, total owed, paid, remaining, advance remaining, status
- Detail: `/people/:id` — owed / paid / remaining, advances issued/settled/remaining, bonuses, deductions, payroll history, advance history, posted adjustments, new payroll record
- Create/edit person: `/people/new`, `/people/:id/edit`
- Explicit copy: these are the company’s people in the Finance workspace, not HASEM staff

Permissions: `payroll.view` / `payroll.manage`.

---

## 9. Salary implementation

- Payroll overview `/payroll` uses Laravel `GET /api/finance/v1/payroll` (dashboard cards + latest records)
- Person detail can create a payroll record (`period`, `basic_salary`, allowances, deductions, payment status, paid_at, notes) via `POST /employees/{id}/payroll-records`
- Cards show Laravel `net_amount` / remaining / payment status

**Limitation (Laravel domain, not Flutter):** payroll records have no `paid_amount` column. For `partial`, remaining is presented as the full `net_amount` because that is what Laravel stores. Flutter does not invent a partial-paid split.

---

## 10. Advance implementation

- List `/advances` — amount, remaining, status, repay action
- Issue advance dialog (employee, type, amount, date, notes)
- Repay dialog posts to Laravel `POST /salary-advances/{id}/repay`
- Person detail shows issued / settled / remaining from Laravel summary
- Advances use real `amount` and `remaining_amount` from `FinanceSalaryAdvance`

Allowances / bonuses / deductions (`/allowances`, `/bonuses`, `/deductions`) wrap existing `FinancePayrollAdjustment` approve / post / cancel.

---

## 11. RTL improvements

- Sidebar placed as the first `Row` child so Arabic `Directionality` puts it on the right
- Page headers: title on the RTL start edge, actions opposite
- Filter bars, tables, KPI icon-on-end layout, chart painters honor `TextDirection`
- Invoice numbers, IDs, and Latin references remain LTR-readable in text widgets
- Compact mobile header avoids squashing Arabic titles

---

## 12. Responsive improvements

- Desktop 1440 / 1920: persistent sidebar, multi-column KPIs, dense tables, single-row filter scroll + pinned apply/reset
- Tablet: KPI wrap, stacked bottom charts under 1100px
- Mobile: collapsed header, stacked page header + actions, stacked filter actions, bottom nav, horizontal table scroll, no 390px overflow (verified in widget visual QA)

---

## 13. API changes

Additive `/api/finance/v1` wrappers over existing Laravel Finance domain (no second accounting engine):

| Method | Path | Permission |
|---|---|---|
| GET/POST | `/employees` | `payroll.view` / `payroll.manage` |
| GET/PUT/DELETE | `/employees/{employee}` | payroll view/manage |
| POST | `/employees/{employee}/payroll-records` | `payroll.manage` |
| GET | `/payroll` | `payroll.view` |
| GET/POST | `/salary-advances` | `finance.salary_advances.view/manage` |
| GET | `/salary-advances/{advance}` | view |
| POST | `/salary-advances/{advance}/repay` | manage |
| GET/POST | `/payroll-adjustments` | `finance.adjustments.view/manage` |
| POST | `/payroll-adjustments/{id}/approve\|post\|cancel` | manage |

Presenter additions: `financeEmployee`, `employeeFinancialSummary`, `payrollRecord`, `salaryAdvance`, `salaryAdvanceRepayment`, `payrollAdjustment`, `payrollOverview`, plus payroll cards on the dashboard presenter.

Auth, Sanctum, `X-Workspace-Id`, Google login, checkout, ZATCA posting, and existing invoice APIs were not rewritten.

---

## 14. Tests

**Flutter** (`apps/hasim_finance`):

- `flutter analyze` — no issues
- `flutter test` — **76 passed** (auth, data parity, feature parity, finance screens, people screens, visual layout capture)

**Laravel:**

- `tests/Feature/Feature/Finance/FinanceFlutterPeopleObligationsApiTest.php` — **4 passed** (43 assertions)

Existing Finance Flutter feature-parity PHPUnit that requires PHP GD remains an environment limitation of this VM, not a regression from this work.

---

## 15. Visual QA

Rendered UI was inspected against the reference (widget-pumped shell at 1440×1100 and 390×844, plus people, person detail, and invoice compose).

Captured:

- `finance_dashboard_desktop_1440.png`
- `finance_dashboard_mobile_390.png`
- `finance_people_desktop_1440.png`
- `finance_person_detail_desktop_1440.png`
- `finance_invoice_compose_desktop_1440.png`

Fixes after inspection:

- Filter bar was wrapping into a tall card — changed to a single horizontal row with pinned apply/reset
- Mobile header crushed the Arabic title — compact header + stacked page header
- Mobile filter actions overflowed 254px — stacked/wrap on &lt;720px
- Form sections sat flush — 12px gap between sections
- List tiles inside decorated boxes failed Material ancestor tests — wrapped in `Material`

Widget captures use the test font fallback (Cairo is fetched at runtime in the real app), so screenshots show layout, not production font rasterization.

This environment has no logged-in Laravel Finance workspace to drive a live `flutter run` session against production data. Layout, RTL, and overflow were verified with the same FakeFinanceApi the parity suite uses. Live pixel-perfect Cairo rendering still needs a signed-in workspace on web/desktop.

---

## 16. Remaining limitations

1. **Payroll partial payments** — Laravel payroll records do not store `paid_amount`. Remaining for `partial` is the full `net_amount`. This is the Web domain model; Flutter does not invent a split. A true “paid 1000 of 1500” salary card requires a Laravel schema/service change, not a Flutter calculator.

2. **No attendance / leave / recruitment** — intentionally omitted. Those are HR workflows, not Finance obligations. Salaries, advances, deductions, bonuses, and settlements **are** included.

3. **Chart series** — bar/donut display Laravel `analytics.series` / `products` when present; if analytics omit series, the chart falls back to sales/expenses/purchases cards. Flutter still does not compute books.

4. **Live browser session against a real workspace** — not available in this agent VM (no Finance-enabled Sanctum session). Widget visual QA + analyze/test were used instead.

5. **PHP GD** — pre-existing `FinanceFlutterFeatureParityTest` attachment/logo assertion can fail where GD is missing. Unrelated to this frontend/people API work.

6. **Some hub bodies** still use `ListTile` rows rather than the denser invoice `DataTable`. Functionality is intact; table density on every hub is leftover polish, not a missing Finance capability.

7. **Google Fonts in tests** — runtime fetch is disabled in widget visual captures, so screenshots do not prove Cairo rasterization.

None of these are classified as “HR-only.” Items 1 and 3 are Laravel-authoritative data limits. Item 2 is scope. Items 4–7 are environment or leftover visual density.
