# TECH-DEBT

Audit date: 2026-09-13

---

## Critical

| Location | Why it matters | Risk | Cost to fix |
| --- | --- | --- | --- |
| `WorkspaceScopedModel` fail-open scope | Cross-tenant reads | Data breach | M |
| Missing CI / Docker / git in this tree | Cannot release safely | Production catastrophe | M |
| Dual tax + offline pricing | Wrong VAT / sales | Financial loss | L |
| Manager elevation = all finance perms | Privilege | Financial loss | M |
| No DB backup automation | Unrecoverable ledger | Catastrophe | M |
| Legal placeholders live | Launch block | Legal | S |

## High

| Location | Why | Risk | Cost |
| --- | --- | --- | --- |
| `InvoiceService` / `PosOrderService` size | Unreviewable changes | Regression | L (do not split without seams) |
| Unscoped EmailLog / attachments / push tokens | IDOR | PII | S–M |
| `finance_invoices.status` legacy | Dual writers | Stale totals | M |
| Four auth controllers | Drift | Auth hole | M |
| `products` vs `pos_menu_items` | Stock mismatch | Finance | L |
| `ai_logs` full bodies | Privacy | Leak | S |
| Database queue default | Lost jobs | Money/messages | S (ops) |
| Duplicate migration timestamp | Operator rewrite | Deploy break | S (docs only) |
| Accountant role mapping | Wrong ACL | Privilege/UX | M |
| No PHPStan | Silent bugs | Medium | L |
| Flutter local chat DB plaintext | Device theft | PII | L |
| No desktop updater | Forever-old clients | Security | L |
| Website/appointment retirement leftovers | Dead tests/docs | Confusion | S |

## Medium

| Location | Why | Risk | Cost |
| --- | --- | --- | --- |
| Dual finance UIs | Drift | UX | L |
| 3 design systems | Slow UI | Low | L |
| `Money::round` float | Drift | Rounding | S |
| Integrity command float | False ops | Low | S |
| Copilot keyword vs LLM expectation | Trust | Product | S |
| Instagram/Messenger flags | False feature | Support | S |
| Missing listeners (only 2) | Incomplete domain events | Sync | M |
| 12 policies vs 200 controllers | Inconsistent authz | IDOR | L |
| Pint not clean | Style noise | Low | M |
| Quotes exist / old spec stale | Agents follow wrong doc | Process | S |

## Low

| Location | Why | Risk | Cost |
| --- | --- | --- | --- |
| `ExampleTest.php` | Noise | None | S |
| Inspire command | Noise | None | S |
| google_fonts runtime | Perf | Jank | S |
| EN locale incomplete | i18n | Low | M |

---

## Code quality signals (not exhaustively listed)

- TODO/FIXME: UNKNOWN full count (not grepped in this pass without flooding vendor)
- `console.log`: Blade/Vite UNKNOWN
- Circular deps: likely in fat services
- Magic strings: membership roles, account codes 1000/1100/1200…
- Huge functions: invoice create/issue, POS checkout

Do **not** “fix debt” by splitting the monolith or adding a second database.
