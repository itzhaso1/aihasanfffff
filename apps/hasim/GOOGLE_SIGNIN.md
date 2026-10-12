# Google Sign-In setup (حاسم)

The Flutter client calls `google_sign_in`, then sends the token to:

`POST /api/mobile/v1/auth/social` with `{ provider: "google", access_token }`.

If Google client IDs are missing or misconfigured, the login button must **not** fake success. The UI shows **يحتاج إعداد Google**.

## Dart defines / environment (no secrets in git)

Pass client IDs at build/run time via `--dart-define` or platform config files that are **not** committed with secrets.

| Platform | Requirement |
|----------|-------------|
| Android | OAuth **Web client ID** often needed as `serverClientId` for backend token exchange; Android OAuth client (package `sa.hasim.hasim` + SHA-1) in Google Cloud Console |
| iOS | iOS OAuth client; URL scheme / `GIDClientID` in `Info.plist` / `GoogleService-Info.plist` as needed by `google_sign_in` |
| Web | Web OAuth client ID (meta tag / `clientId` for web) |

Suggested defines (names only — values stay in CI/secrets):

```bash
flutter run \
  --dart-define=API_BASE=https://your-domain.com \
  --dart-define=GOOGLE_SERVER_CLIENT_ID=your-web-client-id.apps.googleusercontent.com
```

Wire `GOOGLE_SERVER_CLIENT_ID` into `GoogleSignIn(serverClientId: ...)` when you harden production builds.

## Backend

Laravel `SocialAuthService` must accept Google access tokens for provider `google`. Do not store OAuth client secrets in the Flutter app.

## Checklist

1. Create OAuth clients in Google Cloud for Android / iOS / Web  
2. Enable Google Sign-In API  
3. Add SHA-1 (debug + release) for Android  
4. Configure iOS URL schemes  
5. Confirm `POST /auth/social` succeeds with a real token  
6. Without config: UI shows «يحتاج إعداد Google» and stays logged out  
