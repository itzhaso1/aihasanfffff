# Hasim Finance (`apps/hasim_finance`)

Production Flutter client for the HASEM **Finance** product.

Laravel remains the source of truth. This app does **not** calculate tax, invoice totals, payment status, permissions, or GL locally. Online checkout goes through Finance invoice checkout → Shared Payments. It does **not** implement POS, Inbox, or AI.

## API

- Base: `{host}/api/finance/v1`
- Auth: Sanctum bearer token stored in secure storage (`hasim_finance_access_token`)
- Workspace: `X-Workspace-Id`
- Sales invoices: `/sales-invoices` (Phase 10 e-invoice `/invoices` is a different contract)

See repository docs:

- `FINANCE_FLUTTER_RECONNAISSANCE.md`
- `FINANCE_FLUTTER_API_INTEGRATION.md`

## Run

```bash
cd apps/hasim_finance
flutter pub get
flutter run -d windows
# or: flutter run -d chrome / android / ios
```

Override the API host in Settings after login, or before first launch via shared preferences.

## Test

```bash
cd apps/hasim_finance
flutter analyze
flutter test
```
