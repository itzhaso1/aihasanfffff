# Google Sign-In (حاسم للمالية)

Finance Flutter uses the **same Laravel User** as the rest of HASEM. Google is only a credential provider. Laravel verifies the token and issues the Sanctum session used by `/api/finance/v1`.

## Flow

**Android / iOS / Web**

1. `google_sign_in` (public client ids only)
2. Google access token (in memory)
3. `POST /api/finance/v1/auth/google` `{ access_token, device_type: finance }`
4. Store Sanctum token in `hasim_finance_access_token`

**Windows** (and other desktops without the plugin)

1. `POST /api/finance/v1/auth/google/start`
2. Open `auth_url` in the system browser
3. Poll `GET /api/finance/v1/auth/google/status?ticket=`
4. `POST /api/finance/v1/auth/google` with the returned Google access token

The browser callback is the existing Laravel route `GET /auth/google/callback`.

## Dart defines (no secrets in git)

```bash
flutter run \
  --dart-define=API_BASE=https://your-domain.com \
  --dart-define=GOOGLE_OAUTH_CLIENT_ID=your-platform-client-id.apps.googleusercontent.com \
  --dart-define=GOOGLE_OAUTH_SERVER_CLIENT_ID=your-web-client-id.apps.googleusercontent.com
```

| Define | Use |
| --- | --- |
| `GOOGLE_OAUTH_CLIENT_ID` | Optional platform client id for `GoogleSignIn.clientId` |
| `GOOGLE_OAUTH_SERVER_CLIENT_ID` | Web client id as `serverClientId` (typical on Android) |

Never pass `GOOGLE_CLIENT_SECRET` (or any Laravel/payment secret) into the Flutter app.

## Laravel env

```
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://your-domain.com/auth/google/callback
```

Without these, `/auth/google/start` returns 422 and the UI shows **يحتاج إعداد Google**.

## Checklist

1. Google Cloud OAuth clients for Android, iOS, and Web
2. Enable Google Sign-In / Google+ API as required by Google Cloud
3. Android SHA-1 (debug + release), package aligned with the Finance app
4. iOS URL scheme / `GIDClientID` as required by `google_sign_in`
5. Confirm `POST /api/finance/v1/auth/google` with a real token in staging
6. Windows: confirm redirect URI and that the browser ticket HTML says **حاسم للمالية**
