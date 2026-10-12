# Open Questions — Requires Project Owner Input

These cannot be answered from the current checkout alone.

1. **Repository completeness** — Is `/home/deploy/aihasan/aihasan` a full git clone or a production deploy subset? Why are `routes/finance-api.php`, `routes/cashier.php`, `app/Services/Finance/`, and `apps/hasim_finance` / `apps/hasim_cashier` missing while migrations and docs reference them?

2. **Production vs documentation** — Does production at `hasim.online` run a different artifact (full monolith) than this workspace path?

3. **Finance module** — Are finance features served only from another branch/server, or were they removed intentionally from this deploy?

4. **Test suite health on canonical repo** — What is the current CI pass rate on `main`? (Not executed in this audit.)

5. **MySQL vs SQLite** — Which database engine runs production? Are there known SQLite/MySQL divergences beyond documented journal date formatting?

6. **Queue/redis** — Is Redis required in production for inbox AI, webhooks, email sync? (`QUEUE_CONNECTION` name only — value not read from `.env`.)

7. **Commerce payments roadmap** — When will `PaymentService` allow `order.source === 'commerce'`? Is checkout intentionally draft-only?

8. **Platform AI model** — Who owns allow-list updates when admins select models outside `config/ai.php` (production incident: `gemini-3.6-flash` vs allowed list)?

9. **Gmail** — Is Google OAuth inbox a planned feature or out of scope vs SMTP/IMAP `EmailAccount`?

10. **Data retention** — Legal retention for AI logs, email bodies, attachments (`AI_LOG_RETENTION_DAYS` etc.) — operational policy not in code.

11. **Worker payouts** — Are platform admin payout flows live with real money movement or record-keeping only?

12. **ZATCA / e-invoicing** — Target go-live jurisdiction and whether production crypto is enabled or still fail-closed per Phase 10 docs.

**No secrets or `.env` values were collected for this list.**
