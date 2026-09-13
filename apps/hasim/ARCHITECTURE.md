# حاسم — Flutter Architecture (V2)

## 1) API Contract (Mobile v1)

Base: `{API_BASE}/api/mobile/v1`  
Auth: `Authorization: Bearer {token}`  
Workspace: `X-Workspace-Id: {id}`  
Envelope: `{ success, data, meta?, message? }`

| Area | Endpoints |
|------|-----------|
| Auth | login, logout, me, forgot-password, reset-password, social, PATCH profile, PUT password, POST avatar |
| Workspace | workspaces, switch |
| Home | `/home`, `/unread`, `/search` |
| Conversations | list/messages/send/read/archive/mute + attachments |
| Email | accounts, inbox/sent/drafts, show/send/read/star |
| Channels / Plan | `/channels`, `/plan`, `/plans`, `/branding` |
| Customers / Notifications / AI / Devices / Sessions | as in `docs/mobile-api.md` |

**Rule:** do not invent endpoints beyond the Mobile API docs.

## 2) Architecture

```
UI (Screens/Widgets)
  → Controllers/Notifiers (Riverpod)
    → Repositories
      → ApiClient (Dio) + onUnauthorized stream
      → LocalCache (Hive) / SecureStore / PrefsStore
RealtimeService + PushService (abstract)
```

## 3) Auth session & 401

`ApiClient` emits `unauthorizedEvents` (and optional callback) on HTTP 401.  
`AuthController` listens and clears token/workspace without circular DI.

## 4) Theming & l10n

- `AppTheme.light()` / `AppTheme.dark()` — brand `#06C2A4`, Cairo  
- `ThemeModeController` → SharedPreferences (`light`/`dark`/`system`)  
- `flutter gen-l10n`: `lib/l10n/app_ar.arb` primary, `app_en.arb` stubs  
- RTL forced in `HasimApp` builder

## 5) Navigation

`initialLocation: /splash` → `/login` or `/conversations` after bootstrap (messaging-first).

Bottom tabs: المحادثات · البريد · الحجوزات · المزيد

Legacy `/home` redirects to `/conversations`. Activity stats remain at `/activity` via More.

Tabs: الرئيسية · المحادثات · البريد · الحجوزات · المزيد

Extra routes: profile, plans, channels, notification-preferences, customers/:id, forgot/reset password.

## 6) Local cache

Hive boxes `conversations_cache` / `messages_cache` — show cache first, refresh from network.

## 7) Google Sign-In

See `GOOGLE_SIGNIN.md`. Missing client config surfaces Arabic snackbar «يحتاج إعداد Google» — never fakes success.
