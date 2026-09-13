# AUTH-AUDIT

Audit date: 2026-09-13  
No authentication bypass was executed live.

---

## 1. Surfaces

| Surface | Files | Mechanism |
| --- | --- | --- |
| Web login/register/reset/verify | `routes/auth.php`, `app/Http/Controllers/Auth/*` | Session, Breeze |
| Phone OTP | `routes/web.php` `/otp/*`, `PhoneOtpController` | Cache-hashed OTP |
| Social web | `SocialLoginController` google/facebook | Socialite |
| Core API | `routes/api.php` `/api/auth/*` | Sanctum |
| Mobile | `routes/mobile.php` | `MobileAuthService` |
| Cashier | `routes/cashier.php` | same family + PIN locally |
| Finance Flutter | `routes/api.php` finance auth group | Sanctum + Google start/status |
| Platform | `routes/platform.php` | `platform_admin` guard |

---

## 2. Control checklist

| Control | Status | Evidence |
| --- | --- | --- |
| Login | Implemented | Web + 4 API families |
| Signup | Implemented | `RegisteredUserController`, `POST /api/auth/register` throttle 10/min |
| Logout | Implemented | session destroy + Sanctum token revoke endpoints |
| Password reset | Implemented | web + mobile/cashier/finance forgot/reset, throttled |
| Email verification | Implemented (MustVerifyEmail on User) | `routes/auth.php` signed + throttle 6/min |
| Phone verification / OTP | Implemented | hashed, 5 min, attempt cap (docs + tests) |
| Google | Implemented | Socialite + token exchange `/api/auth/social/token` |
| Facebook | Implemented (web/API provider list) | `SocialAuthService` allows google\|facebook |
| Sessions | Laravel session | production checklist encrypt + secure cookie |
| Tokens | Sanctum personal access | per-token TTL 30/60 days |
| Refresh tokens | **Missing** as a first-class rotation protocol | logout + re-login |
| Cookies | Framework defaults | UNKNOWN live flags |
| Remember me | Breeze default | UNKNOWN if enabled in view |
| Multi-device | Mobile sessions list/delete | `DeviceSessionController` |
| Expiration | Per-token, global null | `config/sanctum.php` |
| Account recovery | Email reset + OTP | no government ID recovery |
| Brute force | Throttles on login/OTP | `throttle:10,1`, `mobile-login`, named OTP limiters |
| Rate limiting | Present on auth routes | not proven on every web POST |
| CSRF | Web yes | API token auth |
| Password hashing | Laravel hasher | User model |
| Password policy | Framework / Breeze | UNKNOWN complexity beyond Laravel defaults |

---

## 3. Findings

### AUD-AUTH-001
- Category: Authentication
- Severity: High
- Title: Long-lived Sanctum tokens without refresh rotation
- Location: `config/sanctum.php`, `config/security.php` (referenced in PRODUCTION.md)
- Evidence: expiration null globally; mobile/cashier 60 days
- Impact: Stolen device token = 60 days of tenant API
- Exploitability: Medium
- Recommendation: Shorter access TTL + refresh; revoke all on password reset
- Effort: M
- Priority: P1

### AUD-AUTH-002
- Category: Authentication
- Severity: Medium
- Title: Four parallel auth implementations can drift
- Location: `Api\AuthController`, `Api\Mobile\V1\AuthController`, `Api\Cashier\V1\AuthController`, `Api\Finance\V1\AuthController`
- Evidence: duplicated login/forgot/social
- Impact: A patch on one family may not apply to others
- Exploitability: Low
- Recommendation: Keep `MobileAuthService` / `AuthenticationService` as only writers
- Effort: M
- Priority: P2

### AUD-AUTH-003
- Category: Authentication
- Severity: Medium
- Title: Accountant / receptionist roles collapse on persist
- Location: `WorkspaceAccess::persistableMembershipRole`
- Evidence: receptionist → agent; default → member. RANK has accountant=40 but MEMBERSHIP_ROLES enum excludes accountant
- Impact: Product language ≠ DB role; users think they invited an accountant, get member
- Exploitability: Low (integrity / UX) / Medium if UI shows accountant permissions they do not have
- Recommendation: Dedicated migration only if product needs the enum
- Effort: M
- Priority: P2

### AUD-AUTH-004
- Category: Authentication
- Severity: Low
- Title: Default platform admin password in example env
- Location: `.env.example` `PLATFORM_ADMIN_PASSWORD=password`
- Impact: If copied to production, full platform takeover
- Recommendation: Force change on first login; generate random in setup
- Effort: S
- Priority: P1

---

## 4. Historical fixes (re-verified as present in code/docs)

Prior repair (`docs/HASEM-POST-REPAIR-AUDIT.md`, 2026-09-02) claimed OTP enumeration, role escalation, API-key authz, inventory authz fixed. Current code still contains `WorkspaceAccess` hierarchy and OTP docs. **Runtime re-test was not executed in this audit.**

UNKNOWN — Requires runtime verification: login lockout after N failures beyond throttle, cookie `Secure`/`HttpOnly`/`SameSite` on a live host, Facebook app review, Google token audience validation.
