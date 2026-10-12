# Hasim Chat — Native Account Registration

**Date:** 2026-10-05  
**Scope:** Company account creation from `apps/hasim` against Laravel `/api/mobile/v1`.  
**Not in this phase:** Independent Worker self-registration, Finance/POS/Cashier, Subscription checkout, new verification APIs, Facebook signup UI, OTP signup.

Independent Worker remains outside `workspace_users`. Company signup does **not** create a worker profile. Worker accounts stay on the existing web flow at `/workers/register`.

Verification split:

| Layer | Result |
| --- | --- |
| Code verified | Yes. Mobile register contract, Flutter Register UI, session/router. |
| Integration verified | Yes. Laravel HTTP tests + Flutter widget/repository tests. |
| Real-device/API live signup | **No.** This environment did not complete a live mail/Google Sign-In round trip on a physical device. |

---

## Laravel registration flow (current, from code)

Canonical company signup is web `GET/POST /register` (`RegisteredUserController`).

Fields:

| Field | Required | Notes |
| --- | --- | --- |
| `name` | yes | max 255 |
| `email` | yes | unique on `users.email`, lowercase |
| `phone` | no | unique on `users.phone` when present |
| `password` + `password_confirmation` | yes | `Password::defaults()` (min 8) |
| `workspace_name` | no | UI label «اسم النشاط». Empty → `{name} Workspace` |

`AuthenticationService::createUserAndWorkspace()` then:

1. Creates the `User` (password hashed via the model cast).
2. `WorkspaceService::createForUser()` creates a workspace (`type` hard-coded `company` on web), unique `slug`, owner membership (`owner` / `active` / `is_primary`), Spatie team role `owner`.
3. Attaches the default active plan subscription (`starter` if present, else first official/active plan) as `trialing` for 14 days.
4. Sets `email_verified_at = null`.
5. Fires `Registered` (web only, before this phase). Laravel sends `VerifyEmailNotification` (signed URL, template `email_verification`).

Web then session-logins and redirects to `workspace.dashboard`. Workspace dashboard is **not** behind `verified`. The `verified` middleware is used on some Independent Worker write routes, not company inbox.

Other existing signup paths (unchanged):

- `POST /api/auth/register` — legacy JSON API, same `AuthenticationService::register()`, issues an `api` token, does **not** fire `Registered`.
- `POST /api/mobile/v1/auth/social` — Google/Facebook access token. `SocialAuthService` **provisions** a user if missing (this is social signup+login). New social workspaces use type `individual`, and email is marked verified.
- Phone OTP (`/api/auth/otp/*`, web OTP login) — login for an **existing** phone, not company registration.
- Employee invitations — invitee must already have a user; not self-signup.
- Independent Worker — `GET/POST /workers/register` (`WorkerRegistrationRequest`: name, email, optional phone, password). Creates a platform user + worker profile, **no** `workspace_users` row.

There was **no** `POST /api/mobile/v1/auth/register` before this phase.

---

## Mobile contract

Added:

`POST /api/mobile/v1/auth/register`  
Public. Throttle: `mobile-login` (10 / minute / IP). Same envelope as login.

Body:

```json
{
  "name": "نورة العتيبي",
  "email": "noura@example.test",
  "password": "password",
  "password_confirmation": "password",
  "phone": null,
  "workspace_name": "متجر نورة",
  "device_name": "حاسم Flutter",
  "device_type": "mobile"
}
```

`workspace_type` is **not** accepted from the client (matches web: always `company`).

`201` response: same login envelope (`token`, `token_type`, `expires_at`, `user`, `participant`, `workspace`, `workspaces`). `participant.type` is `company_staff`.

Implementation reuses `AuthenticationService::registerForSession()` then `MobileAuthService::issueDeviceToken()` (abilities `mobile`, `*`, mobile token TTL). Fires `Registered` like web so the verification mail is sent. Empty `phone` / `workspace_name` are stored as null.

Documented in `docs/mobile-api.md`.

---

## Flutter flow

Login already showed «إنشاء حساب» as inert text. It is now a button to `/register`.

`RegisterScreen` (RTL):

1. الاسم الكامل  
2. البريد الإلكتروني  
3. رقم الجوال (اختياري)  
4. اسم النشاط (اختياري)  
5. كلمة المرور  
6. تأكيد كلمة المرور  
7. إنشاء حساب  
8. التسجيل عبر Google (existing `POST /auth/social`)  
9. لديك حساب؟ تسجيل الدخول  

Client checks: required fields, email contains `@`, password length ≥ 8, confirmation match. Server 422 field errors are translated to Arabic (`ApiErrorTranslator`). Duplicate submit is ignored while loading. Password is sent once and is not written to prefs/secure storage.

On success, `AuthController.register` reuses `_applyLogin`: Sanctum token in secure storage, workspace id in prefs, inbox session, redirect to `/conversations` (or `/workspaces` if none — company signup always creates one). Guest `/register` is public; an already-authenticated user is redirected away.

---

## Workspace creation

Created in the same request as the user (not a second onboarding step).

- Name: `workspace_name` or `{name} Workspace`
- Slug: `Str::slug($name).'-'.random(6)` — duplicate display names do not collide
- Owner: `owner_user_id` + `workspace_users.membership_role = owner`
- Default plan/trial as in `WorkspaceService`
- No second workspace on duplicate email (unique email 422)

Flutter has no extra «choose workspace name» step after submit. The optional field is on the same form.

---

## Email verification

- User implements `MustVerifyEmail`.
- Mobile register fires `Registered` → `VerifyEmailNotification` with a **signed web URL**.
- There is no mobile-safe verification endpoint, and none was invented.
- `email_verified_at` stays null until the signed link is opened.
- Mobile login/`/auth/me` do **not** require a verified email. Company web dashboard does not either.
- Flutter therefore logs the user into Hasim Chat immediately. Opening the mail link is optional for company chat, required later only for some worker-web writes.

OTP is not part of company registration.

---

## Social signup

| Provider | Laravel web | Mobile contract | Flutter |
| --- | --- | --- | --- |
| Google | OAuth redirect + token provisioning | `POST /auth/social` `{provider:google, access_token}` | Login + Register buttons. Provisions user if new. |
| Facebook | Web social redirect | Same mobile contract (`facebook`) | **Not** in Flutter UI (login also Google-only). |
| OTP | Web OTP login | Legacy `/api/auth/otp/*` only | Not used for signup. |

Google on the register screen is the existing mobile social login, not a new endpoint. It still needs native Google Sign-In client IDs. New Google users get workspace type `individual` (existing `SocialAuthService` behavior, not changed).

---

## Security

- Rate limit: `throttle:mobile-login` (10/min/IP), same as login/forgot/reset/social.
- CSRF: Sanctum token API, not session cookies.
- Password hashed (`hashed` cast). Never stored on the device.
- Email/phone uniqueness at validation + DB.
- Token is a mobile device token with `workspace_id` set to the new workspace.
- Owner assignment is server-side.
- Duplicate email returns 422 `email` unique (same enumeration behavior as web).
- `workspace_type` cannot be chosen from mobile.
- Independent Worker is not created on this route.
- Credentials are posted only to `/api/mobile/v1/auth/register` or `/auth/social`. No web fallback form.

---

## Files changed / added

**Laravel**

- `app/Http/Controllers/Api/Mobile/V1/AuthController.php` — `register()`
- `app/Services/Mobile/MobileAuthService.php` — `register()`
- `routes/mobile.php` — `POST /auth/register`
- `docs/mobile-api.md` — authentication table
- `tests/Feature/Mobile/MobileAuthRegistrationTest.php` — new

**Flutter**

- `apps/hasim/lib/features/auth/presentation/register_screen.dart` — new
- `apps/hasim/lib/features/auth/presentation/login_screen.dart` — tappable «إنشاء حساب»; public `HasimGoogleMark`
- `apps/hasim/lib/features/auth/data/auth_repository.dart` — `register()`
- `apps/hasim/lib/features/auth/providers/auth_controller.dart` — `register()`
- `apps/hasim/lib/router/app_router.dart` — `/register`
- `apps/hasim/lib/router/auth_redirect.dart` — public + post-auth
- `apps/hasim/lib/core/network/api_error_translator.dart` — register fields, 429, unique, confirmation
- `apps/hasim/test/register_screen_test.dart` — new
- `apps/hasim/test/auth_register_repository_test.dart` — new
- `apps/hasim/test/api_error_translator_test.dart` — duplicate email + 429

Unchanged on purpose: `RegisteredUserController`, `WorkerRegistrationController`, `/api/auth/register`, social/OTP services.

---

## Routes changed / added

| Method | Path | Change |
| --- | --- | --- |
| POST | `/api/mobile/v1/auth/register` | **Added** |

Web `/register`, `/workers/register`, `/api/auth/register`, `/api/mobile/v1/auth/login`, `/auth/social` unchanged.

---

## Tests

**Laravel** (`MobileAuthRegistrationTest` + regression):

- 201 success: user, hashed password, company workspace, owner membership, trial subscription, `company_staff`, token works on `GET /auth/me`, `email_verified_at` null, verification notification sent, no worker profile
- omitted workspace name → `{name} Workspace`
- 422 duplicate email, missing fields, password mismatch, short password, duplicate phone
- duplicate email does not create a second workspace
- 429 after 10 attempts on the same IP

Also passed: `RegisterTest`, `RegistrationTest`, `RegisterWithPlansTest`, `EmailVerificationTest`, `AuthenticationTest`, `MobileAuthAndWorkspaceTest`, `SocialAuthProvisioningTest`, `ProfileTest`. Pint `--dirty` passed.

**Flutter:**

- Open Register from Login
- Client validation (required + confirmation)
- Successful register → token + inbox
- Duplicate email Arabic error, no session
- Loading ignores a second submit
- Repository posts `/auth/register` and parses the login envelope
- Router: guest `/register` allowed; authenticated user leaves it

`flutter analyze` clean.

Full Flutter suite: **439 passed, 2 failed**. Failures are the existing leftovers `app_config_test` and `inner_chrome_corner_test`, not registration.

---

## Blockers

1. **Live mail / device** — verification email and Google Sign-In were not exercised on a real device or production SMTP.
2. **Independent Worker** — still web-only (`/workers/register`). Not part of company native signup (by existing design).
3. **Facebook** — mobile social contract exists; Flutter UI is Google-only (same as login).
4. **Email verification** — signed web link only. Company chat does not require it. No mobile OTP/verify endpoint exists.

---

## Real-device / API verification

- Route is registered: `POST api/mobile/v1/auth/register`.
- Feature tests hit that path in-process (SQLite in-memory), not a hosted API.
- No physical phone signup in this pass.

---

| Item | Laravel | Flutter | Status | Notes |
| --- | --- | --- | --- | --- |
| Register UI | Web `/register` | `/register` from Login | Done | RTL form, not a webview |
| Mobile register endpoint | `POST /api/mobile/v1/auth/register` | `AuthRepository.register` | Done | Was missing; added |
| Validation parity | Web rules (`Password::defaults`, unique email/phone) | Client + server errors in Arabic | Done | |
| Session / token | Mobile device Sanctum token | Secure storage + `_applyLogin` | Done | |
| Workspace | Created with owner + trial plan | Uses returned workspace | Done | Optional اسم النشاط |
| Email verification | `Registered` → signed mail link | No blocking screen | Done as Laravel actually behaves | Not required for company inbox |
| OTP signup | Not in company register | Not shown | N/A | OTP is login for existing phones |
| Google signup | `/auth/social` provisions users | Register + Login buttons | Done | Needs Google client config |
| Facebook signup | Mobile contract exists | No UI | Partial | Same as login |
| Worker self-signup | `/workers/register` | Not on this form | Out of scope | Stays off `workspace_users` |
| Rate limit | `mobile-login` 10/min | 429 Arabic | Done | |
| Web fallback required? | Web still works | Not used for the happy path | Done | |

**هل يستطيع المستخدم إنشاء حساب Hasim Chat كاملًا من التطبيق بدون Web؟ نعم** — لحساب الشركة (الاسم، البريد، كلمة المرور، مساحة العمل، الدخول إلى المحادثات). مسار العامل المستقل ما زال عبر `/workers/register` على الويب فقط، وهذا تصميم قائم وليس جزءًا من التسجيل العادي.
