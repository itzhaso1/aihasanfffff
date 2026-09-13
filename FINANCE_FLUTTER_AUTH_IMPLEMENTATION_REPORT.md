# FINANCE FLUTTER AUTH IMPLEMENTATION REPORT

Finance Flutter (`apps/hasim_finance`) authenticates against the **same Laravel User / workspace / Sanctum identity** as the rest of HASEM. Flutter is only a client. There is no Finance-only user table, no Google-only Flutter user, and no second token system.

## 1. Existing authentication architecture

Laravel remains the identity authority.

| Layer | Role |
| --- | --- |
| `users` | Canonical account |
| `auth_identities` | Google (and other) provider links (`provider` + `provider_user_id`) |
| `workspaces` + membership pivot | Tenant membership |
| Laravel Sanctum | API session (`PersonalAccessToken`, optional `workspace_id`) |
| `MobileAuthService` | Password + social login, device token issuance (`device_type=finance`) |
| `SocialAuthService` | Google access token → Socialite `userFromToken` → User + AuthIdentity + workspace |
| `FeatureAccessService` | `finance_enabled` |
| Spatie permissions + Finance elevation | Permission map (`finance.view`, `invoices.*`, …) |

Finance password login was already:

`POST /api/finance/v1/auth/login` → `AuthController::login` → `MobileAuthService::loginWithPassword` with `device_type=finance`.

Envelope: `{ success, data: { token, user, workspace, workspaces, permissions, finance_enabled }, message }`.

Token storage (unchanged): `flutter_secure_storage` key `hasim_finance_access_token`. Workspace id: preferences key `hasim_finance_workspace_id`.

## 2. Existing Google authentication discovery

HASEM already had Google login. Finance **reuses it**; it does not add a second Google verifier.

| Surface | Endpoint / class |
| --- | --- |
| Shared Google token → User | `App\Services\Auth\SocialAuthService::loginWithAccessToken` |
| Mobile/Chat social | `POST /api/mobile/v1/auth/social`, `POST /api/auth/social/token` |
| Cashier social | `POST /api/cashier/v1/auth/social` |
| Shared browser OAuth ticket | `App\Services\Cashier\CashierGoogleBrowserLogin` + `GET /auth/google/callback` |
| Cashier Windows start/status | `POST /api/cashier/v1/auth/google/start`, `GET /api/cashier/v1/auth/google/status` |
| Chat Flutter SDK helper | `apps/hasim` + `google_sign_in` |
| Cashier Flutter token helper | `apps/hasim_cashier/lib/core/auth/google_access_token.dart` |

Socialite verifies the Google credential **on the server** (`stateless()->userFromToken($accessToken)`). Flutter never receives `GOOGLE_CLIENT_SECRET` and never treats a client-supplied email as proof of identity.

## 3. Reused components

- `MobileAuthService::loginWithSocial` (same Sanctum device token path as password)
- `SocialAuthService` account resolution / linking
- `CashierGoogleBrowserLogin` (shared OAuth ticket cache; `start('finance')` only changes the callback HTML product label)
- Finance session presenter / permission map already used by password login
- `flutter_secure_storage` + existing Finance `AuthController` / router
- `google_sign_in` (same family as Chat/Cashier; Finance does **not** copy Cashier desktop client-secret OAuth)

Not reused: Cashier POS screens, Chat inbox login UI, Booking, payments-provider secrets.

## 4. New Laravel endpoints

All under `/api/finance/v1`. Password login / forgot / reset / logout / me were already present.

| Method | URL | Auth | Notes |
| --- | --- | --- | --- |
| POST | `/auth/google` | none (throttled) | Body `{ access_token, workspace_id?, device_name?, device_type=finance }`. Alias of social with `provider=google`. |
| POST | `/auth/social` | none | Google only (`provider` `in:google`). Same payload as password login. |
| POST | `/auth/google/start` | none | Browser OAuth ticket for Windows / plugin fallback. Requires Laravel `GOOGLE_CLIENT_ID` + `GOOGLE_CLIENT_SECRET`. |
| GET | `/auth/google/status` | none | Poll ticket. `ready` returns the Google **access** token once, then forgets it. |

Callback remains the existing shared route `GET /auth/google/callback` (not a Finance-only OAuth app).

Logout now also deletes the bearer PAT (if still present) and invalidates the web guard/session so a stateful Sanctum cookie cannot keep `/auth/me` alive after token revoke.

## 5. New Flutter components

| Path | Role |
| --- | --- |
| `lib/core/auth/google_auth.dart` | `GoogleAccessTokenSource`, cancellation |
| `lib/core/auth/google_access_token.dart` | Plugin (Android/iOS/Web) or Laravel browser ticket (Windows/desktop) |
| `lib/features/auth/login_screen.dart` | Password + Google + forgot link |
| `lib/features/auth/forgot_password_screen.dart` | Forgot + reset (token/email/password) |
| `lib/features/auth/workspace_gate_screens.dart` | Workspace picker + finance-unavailable |
| `lib/core/auth/auth_controller.dart` | Google login, workspace gate, finance eligibility, bootstrap |
| `lib/core/routing/app_router.dart` | Public auth routes; no redirect loops |
| `test/auth_screens_test.dart`, `test/auth_controller_test.dart` | Auth UI/session tests |
| `apps/hasim_finance/GOOGLE_SIGNIN.md` | Platform setup (public client ids only) |

## 6. Account linking behavior

**Existing HASEM policy in `SocialAuthService` (reused, not reinvented):**

1. Match `auth_identities` on `provider=google` + Google user id → that Laravel `User`.
2. Else `User::firstOrCreate(['email' => google email])` and create `AuthIdentity`. Matching email **does not create a duplicate user**.
3. If the email is new, HASEM already self-registers (random password, verified email, workspace via `WorkspaceService`). Finance does not add a second registration product.

Finance does **not** invent a “log in with password first” merge wizard, because that would diverge from Chat/Cashier/mobile. If Laravel later returns **409**, the login screen shows:

> هذا البريد مرتبط بحساب موجود. سجّل الدخول بكلمة المرور أولاً لربط حساب Google.

## 7. Workspace behavior

Login (password or Google) returns `workspace`, `workspaces[]`, and `finance_enabled` per workspace.

- One workspace: stored and used; Finance bootstrap runs if `finance_enabled`.
- **More than one:** Flutter sets `needsWorkspaceSelection` and routes to `/workspaces`. It does **not** pick an arbitrary tenant. User taps one → `POST /workspaces/switch` → `GET /auth/me` → `GET /bootstrap` when eligible.
- Session restore (`GET /auth/me`) does **not** force the picker again.

## 8. Permission behavior

Google and password share `AuthController::sessionPayload` → `financePermissionMap`. Google users are not admins. Examples still come from Laravel: `finance.view`, `invoices.view|create|issue`, `payments.view|manage`, `receipts.view`, `reports.view`, etc.

## 9. Security model

| Control | Behavior |
| --- | --- |
| Google credential | Validated by Laravel Socialite against Google. Client email strings are not trusted. |
| Secrets | `GOOGLE_CLIENT_SECRET` stays in Laravel `.env`. Flutter only has optional public `--dart-define` client ids. |
| Tokens in UI | Google access token is held in memory long enough to POST `/auth/google`, then discarded. Sanctum token is in secure storage only. |
| Logging | Controllers do not log passwords, Sanctum tokens, Google tokens, or OAuth secrets. Invalid Google returns generic `تعذر التحقق من حساب Google.` (401) without provider internals. |
| HTTPS | Production API base must be HTTPS; OAuth redirect is Laravel `GOOGLE_REDIRECT_URI`. |
| Sanctum | Device token `device_type=finance`. Logout revokes PAT + web session. |
| Workspace | Member middleware + global scopes; cross-workspace reads 404 (`FinanceFlutterClientApiTest`). |
| Permissions | Server map; UI gates; Laravel still authorizes mutations. |
| Finance eligibility | `finance_enabled` from `FeatureAccessService`. Session is kept; shell is blocked. |

## 10. Platform support

Official Finance targets: **Android, iOS, Web, Windows**.

| Platform | Google flow | Notes |
| --- | --- | --- |
| Android | `google_sign_in` → access token → `POST /auth/google` | Needs Android OAuth client + SHA-1 and usually a web `serverClientId`. |
| iOS | same plugin | Needs iOS OAuth client / URL scheme. |
| Web | plugin (`kIsWeb`); browser ticket fallback if plugin fails | Needs web client id. |
| Windows | **Laravel browser ticket only** (`/auth/google/start` + `/status`) | `google_sign_in` does **not** ship a Windows implementation. Finance does **not** embed a Google client secret (Cashier desktop helper must not be copied). |
| macOS / Linux | Browser ticket if used | Not official Finance targets. |

Misconfigured Google Cloud / missing dart-defines: UI shows `يحتاج إعداد Google` and stays logged out (no fake success).

User cancel: return to login, not a fatal error.

## 11. Forgot-password support

| Piece | Status |
| --- | --- |
| `POST /auth/forgot-password` | Existing Laravel; Flutter screen submits email |
| Email | Existing `ResetPasswordNotification` (web `action_url`) |
| `POST /auth/reset-password` | Existing Laravel; Flutter screen accepts email + token + new password |

The reset **email link still opens Laravel web**, not a Finance Flutter deep link. Users can paste the token into the in-app reset screen.

## 12. Tests

### Flutter (`apps/hasim_finance`)

- Login labels, RTL, English Google/forgot strings
- Password login + invalid password
- Google loading, success, cancel, error, 409 linking copy
- Forgot + reset screens
- Workspace list, finance-unavailable
- Restore, expired token, 401 logout, logout, multi-workspace gate, finance disabled, Google permissions

### Laravel (`FinanceFlutterAuthTest`)

- Email / phone / `email_or_phone` password login
- Invalid password 401
- Google linked user + token + permissions
- Matching email does not duplicate User
- Invalid Google 401 without raw provider text
- Missing token / non-google provider 422
- Google start without env 422
- Shared browser ticket + finance-branded callback HTML
- Multiple workspaces listed
- Finance disabled for password **and** Google
- Logout then `/auth/me` 401
- Unauthenticated `/auth/me` 401
- Forgot + reset password

Cross-workspace 404 and agent 403 remain in `FinanceFlutterClientApiTest` (not weakened).

Cashier Google tests still pass (`CashierGoogleSocialAuthTest`) so the shared ticket class did not break Cashier.

## 13. Exact test counts

Recorded from this run (not estimated):

| Command | Result |
| --- | --- |
| `cd apps/hasim_finance && flutter analyze` | No issues found (2.7s) |
| `cd apps/hasim_finance && flutter test` | **42 passed** |
| `php vendor/bin/phpunit tests/Feature/Feature/Finance/FinanceFlutterAuthTest.php tests/Feature/Feature/Cashier/CashierGoogleSocialAuthTest.php --no-coverage` | **26 passed**, 109 assertions (15 Finance auth + 11 Cashier Google; finance-disabled Google assertion is inside the existing disabled-workspace test) |
| `php vendor/bin/phpunit tests/Feature/Feature/Finance tests/Feature/Feature/Api/OrderPaymentFlowTest.php --no-coverage` | **322 tests, 320 passed, 2 skipped, 1 risky**, 2854 assertions |
| `php vendor/bin/phpunit --no-coverage` | **738 tests, 734 passed, 4 skipped, 1 warning, 1 risky**, 5454 assertions |

Skipped / warning / risky are pre-existing (DomPDF, missing local crypto fixtures, UBL XML risky test, `openssl_x509_read` in `X509CertificateParser`). No tests were deleted, skipped, or weakened for this work.

## 14. Known limitations

- Live Google OAuth is not exercised in CI; Socialite is mocked. Production still needs Google Cloud + Laravel env.
- Reset-password email does not deep-link into Finance Flutter.
- Windows Google is browser OAuth, not the Google Sign-In SDK.
- HASEM auto-links by email (firstOrCreate). There is no separate Finance 409 linking protocol unless Laravel starts returning 409.
- Flutter web PDF/CSV download limitation from the earlier Finance client work is unchanged.
- This environment did not run a device-lab Google Sign-In or a packaged Windows installer smoke test.

## 15. Production readiness

**Ready to ship authentication** once Google Cloud OAuth clients and Laravel `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `GOOGLE_REDIRECT_URI` are set in each environment. Password login does not depend on Google.

Do not treat Finance Flutter as a second identity provider.

## 16. Verdicts

| Item | Verdict |
| --- | --- |
| Password Login | **COMPLETE** |
| Google Login | **COMPLETE** for the Laravel contract (same User, Sanctum, permissions). Platform rows below. |
| Logout | **COMPLETE** |
| Session Restore | **COMPLETE** |
| Workspace Selection | **COMPLETE** |
| Permissions | **COMPLETE** |
| Finance Eligibility | **COMPLETE** |
| Forgot Password | **COMPLETE** (request UI + API). In-app reset is **PARTIAL** (token paste; email link is Laravel web). |
| Account Linking | **COMPLETE** as existing HASEM `AuthIdentity` + `firstOrCreate(email)` (not a Finance-only 409 wizard). |
| Android | **COMPLETE** (plugin + Laravel). Requires Google Cloud Android client. |
| iOS | **COMPLETE** (plugin + Laravel). Requires Google Cloud iOS client. |
| Web | **COMPLETE** (plugin + optional browser fallback). Requires web client id. |
| Windows | **COMPLETE** via Laravel browser ticket. **Not** native `google_sign_in`. Do not claim SDK parity with mobile. |
