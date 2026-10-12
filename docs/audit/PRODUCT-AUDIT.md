# PRODUCT-AUDIT

Audit date: 2026-09-13

---

## 1. What is HASIM SMART?

From code and first-party docs (not marketing guesswork):

**HASIM SMART (HASEM / حاسم)** is an Arabic-first **multi-tenant SaaS for small and mid businesses** that combines:

1. **Shared inbox** (WhatsApp + email + manual) and light CRM  
2. **Finance / invoicing / double-entry lite** (quotes, invoices, expenses, payroll, ledger reports)  
3. **POS / cashier** (tables, kitchen, offline Flutter)  
4. **Platform billing** (Starter/Pro/Business/Enterprise) and **merchant KYC** to collect customer money  

Target user: Saudi/regional business owner, accountant, cashier, inbox agent — one **workspace** (individual / company / store).

SaaS = Laravel web + subscriptions + entitlements.  
Desktop = Flutter Windows builds of the three apps.  
Mobile = same three Flutter clients.  
Web = Blade tenant app + landing.  
Core platform = the Laravel monolith and shared DB.

---

## 2. Feature inventory (path completeness)

Status: Complete / Partial / Broken / Missing / Unknown  
Complete requires UI → logic → API/DB → response.

| ID | Feature | Module | FE | BE | DB | API | Auth | Perm | Valid | Tests | Status | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| F01 | Signup / workspace | Identity | Blade | yes | users/workspaces | `/api/auth/register` | guest | n/a | yes | RegistrationTest | Complete | Legal type not required |
| F02 | Login session | Identity | Blade | yes | sessions | many | yes | n/a | yes | AuthenticationTest | Complete | |
| F03 | OTP | Identity | Blade | yes | cache | `/api/auth/otp` | guest | n/a | yes | OtpProtectionTest | Complete | |
| F04 | Google/Facebook | Identity | yes | Socialite | auth_identities | social token | yes | n/a | yes | partial | Partial | Facebook live UNKNOWN |
| F05 | Plans / checkout | Billing | Blade | yes | subscriptions | `/api/subscriptions` | member | yes | yes | Subscription* | Partial | Local provider |
| F06 | Merchant KYC | Payments | Blade + platform | yes | merchant_* | n/a | yes | platform | yes | MerchantVerificationTest | Complete | needed for GMV |
| F07 | Customers | CRM | all UIs | yes | customers | web/mobile/finance/cashier | yes | mixed | yes | isolation tests | Complete | duplicate create risk |
| F08 | Leads | CRM | finance | yes | crm_leads | finance API | finance | yes | yes | PlatformCore | Complete | no full pipeline |
| F09 | Inbox WhatsApp | Chat | Blade+Flutter | yes | conversations | mobile | yes | member | yes | WhatsApp* | Partial | tokens |
| F10 | Inbox email | Chat | Blade+Flutter | yes | email_* | mobile | yes | member | yes | Email tests (some skip) | Partial | |
| F11 | Instagram/Messenger | Chat | flags | no | settings | no | n/a | n/a | no | UI only | Missing | misleading |
| F12 | Telegram/TikTok | Chat | no | no | no | no | n/a | n/a | no | no | Missing | |
| F13 | Stories | Chat | Flutter | yes | stories | mobile | yes | yes | yes | MobileStoriesTest | Complete | |
| F14 | Products / inventory | Catalog | Blade | yes | products | `/api/products` | yes | WorkspaceAccess | yes | InventoryAuthorization | Complete | |
| F15 | POS sale | POS | Blade+Flutter | yes | orders | cashier | yes | POS policies | yes | PosModule* | Partial | offline races |
| F16 | QR guest menu | POS | public | yes | orders | web | no | slug | yes | PosTableQr* | Partial | abuse |
| F17 | Shifts / drawer | POS | Flutter | no ledger | local | no | PIN | local | Flutter tests | Partial | not in GL |
| F18 | Quotes | Finance | Blade+Flutter | yes | finance_quotes | finance-api | finance | map | yes | UNKNOWN full | Partial | spec text stale |
| F19 | Invoices / payments | Finance | yes | InvoiceService | finance_invoices | finance-api | finance | yes | yes | FinancialCore | Complete | KPI drift |
| F20 | Credit notes | Finance | yes | yes | finance_credit_notes | finance-api | yes | yes | yes | present | Complete | |
| F21 | Expenses | Finance | yes | ExpenseService | finance_expenses | finance-api | yes | yes | yes | ExpenseAccounting | Complete | |
| F22 | Journals / TB / P&L | Finance | reports | AccountingService | journals | web+API reports | elevated | yes | yes | PlatformCore | Complete | close quality |
| F23 | Bank rec | Finance | treasury | BankReconciliation | statements | web | yes | yes | yes | IDOR tests | Partial | no import |
| F24 | Payroll | Finance | Blade+API | yes | payroll_* | finance-api | yes | yes | yes | FinancialCore | Partial | |
| F25 | Copilot | Finance | yes | keyword | n/a | POST | finance.view | yes | yes | isolation | Partial | not LLM |
| F26 | E-invoice ZATCA | Finance | checklist UI | harness | e_invoice_* | xml/qr | yes | yes | yes | Phase* harness | Partial | not live |
| F27 | Public assistant | AI | widget | WebsiteAssistant | ai_logs | `/assistant/chat` | no | n/a | 800 chars | AssistantChatTest | Partial | accepted risk |
| F28 | API keys | Platform | Blade | yes | api_keys hash | n/a | owner/admin | yes | yes | ApiKey* | Partial | consume path foundation |
| F29 | Website builder | Web | retired | retired | dropped | n/a | n/a | n/a | n/a | old tests may linger | Missing | do not rebuild casually |
| F30 | Appointments | — | retired | retired | dropped | n/a | n/a | n/a | n/a | old failures in 09-02 audit | Missing | |

---

## 3. Core business flows (summary)

| Flow | Entry | Failure points |
| --- | --- | --- |
| Signup | `/register` | email deliverability UNKNOWN |
| Login | `/login`, APIs | throttle, Socialite state |
| Workspace | created at register / choose | membership enum mapping |
| Customer | web/API/cashier | duplicates |
| Conversation | webhook or manual | WhatsApp secret |
| Order | POS / QR / API | offline duplicate |
| Invoice | finance web/API | tax engine vs POS tax |
| Payment | finance + payment links + POS | KYC, provider, race |
| Refund | credit note / POS return / payment reverse | elevation |
| Expense | finance | reversal path |
| Report | ledger reports | KPI ≠ P&L |
| POS sale | cashier | sync |
| Employee | invite | persistable role |
| Permission | Spatie + elevation | manager = all finance |
| Subscription | checkout | local provider = no real charge |

---

## 4. Feature gaps (from this product’s own flows, not competitors)

- Real subscription charging (Stripe/HyperPay live)
- Live merchant settlement
- Official e-invoicing clearance
- Instagram/Messenger/Telegram actually connected
- Unified product catalog
- Server-side shifts / cash-up in GL
- Quote→order (sales order) as first-class if quotes convert only to invoices
- Credit limit hard block
- Warehouses / FIFO / serials
- Export completeness (CSV/PDF everywhere)
- EN locale parity
- Cookie consent + complete legal
- Support / ticketing
- Account close / data export
- 2FA for owners and platform admin
- Multi-currency posting

---

## 5. Cross-app integration

| Entity | Source of truth | Chat | Finance | POS | Sync risk |
| --- | --- | --- | --- | --- | --- |
| Workspace | Laravel | yes | yes | yes | header X-Workspace-Id |
| User | Laravel users | yes | yes | yes + PIN | |
| Customer | `customers` | yes | yes | can create | **duplicates** |
| Product | `products` | AI catalog | yes | menu items | **dual catalog** |
| Invoice | `finance_invoices` | no | yes | cashier invoices / orders | **three documents** |
| Order | `orders` | no | POS invoice API | yes | offline |
| Payment | `payments` + invoice payments | no | yes | POS | double pay |
| Employee | membership + finance employees | invite | payroll profiles | cashier users | two employee concepts |

Shared DB is the online sync. Events/queues for WhatsApp/email/payments. Cashier + chat use local DBs.

**Duplicate data risk:** customer created in cashier and finance; menu item vs product; POS order vs finance invoice vs cashier invoice (`pos_cashier_invoices` + `pos_cashier_invoice_id` on orders).
