# Security

## Tenancy

- Workspace-scoped Eloquent models refuse cross-workspace writes when context is set.
- Public website resolution binds a single website/workspace from host or slug — never from attacker-controlled foreign IDs without membership checks.
- Public assistant **does not** accept arbitrary `workspace_id` for unauthenticated users; products appear only for authenticated members or a **published** website resolved by host.

## Merchant verification & payments

- Users **cannot** mass-assign `verification_status`, `provider_onboarding_status`, `provider_merchant_id`, or plan IDs from the frontend.
- Merchant document files are stored on the **private** disk (`local` / `storage/app/private`).
- Downloads go through authorized Platform Admin or owning-workspace routes — not public URLs.
- Do **not** log document storage paths as public URLs or include signed URLs in audit payloads beyond IDs.
- Platform Admin approve/reject/suspend/request-documents require platform auth middleware.

## Money contexts

- Merchant payment link creation asserts eligibility server-side (`MerchantPaymentEligibilityService`).
- Platform subscription checkout must never create a `merchant_gmv` payment row.

## API keys

- Table `api_keys` stores `key_hash` (SHA-256) + `key_prefix` only.
- Plaintext `hs_…` secret is shown **once** on create/regenerate.
- Revoke sets `revoked_at`; regenerate rotates hash.

## Assistant hardening

- System prompt forbids secrets, tokens, `.env`, webhook secrets, and cross-tenant dumps.
- Fallback path rejects secret-seeking prompts in Arabic/English heuristics.
- Product **stock** is omitted in public website context.
- Extra per-user/IP message rate limit in `AssistantController` (in addition to route `throttle:20,1`).

## Uploads

- Website assets reject SVG; MIME allow-list JPEG/PNG/WebP/GIF; size capped (~5MB in service).
- Merchant documents: PDF/JPEG/PNG/WebP, size capped (~8MB), private disk.
- Client-side image uploader mirrors accept list + size hint (server still validates).

## Ops reminders

- Never log WhatsApp tokens or `HYPERPAY_ACCESS_TOKEN`.
- Webhook signature verification required in production (`WHATSAPP_APP_SECRET`, Stripe secrets).
- Never enable `HYPERPAY_MERCHANT_SANDBOX_AUTO_APPROVE` in production.

## Auth tokens

- Sanctum global `expiration` is **null** so per-token `expires_at` is authoritative.
- Core API / social tokens expire in 30 days (`config/security.php`).
- Mobile and cashier device tokens expire in 60 days.
- Do not set a global Sanctum expiration longer than the shortest client TTL.

## OTP

- Request and verify endpoints are rate-limited (IP + phone).
- Responses are generic; they do not disclose whether a phone is registered.
- API responses never include the OTP code (including local/staging).
- The web verify form does **not** list workspaces for the phone before OTP succeeds.
- OTP hashes are stored in cache for 5 minutes with a verify-attempt cap.
