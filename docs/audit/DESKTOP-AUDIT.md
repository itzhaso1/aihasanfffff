# DESKTOP-AUDIT

Audit date: 2026-09-13

---

## 1. What exists

Not Electron/Tauri/WPF. All three Flutter apps include **Windows** runners:

- `apps/hasim/windows` (build native assets present)
- `apps/hasim_finance/windows/runner/*`
- `apps/hasim_cashier/windows/runner/*`

Downloads page: `routes/web.php` `/downloads` + `resources/views/downloads.blade.php`.

No first-party installer pipeline (Squirrel/MSIX/Sparkle) found in repo root.

---

## 2. Checklist

| Topic | Status | Evidence |
| --- | --- | --- |
| Packaging | Partial | Flutter windows CMake runners |
| Auto-update | Missing | no sparkle/msix updater package in pubspecs |
| Local storage | Present | same as mobile (secure storage, Drift, Hive) |
| Filesystem | Present | path_provider, file_picker, cashier backup |
| IPC | Flutter engine defaults | no custom native plugin audit |
| Permissions | OS user account | UNKNOWN sandbox |
| Secrets | flutter_secure_storage | Windows credential storage UNKNOWN quality |
| Printer | cashier `printer_service.dart` | UNKNOWN device QA |
| Crash handling | none first-party (no Sentry in pubspec) | Missing |
| Offline | cashier yes; finance no; chat cache | see POS/CHAT |

---

## 3. Findings

### AUD-DESK-001
- Severity: High
- Title: Desktop shipped without update channel
- Location: apps `windows/`, pubspecs
- Impact: Vulnerable clients stay in restaurants/offices forever
- Recommendation: Signed MSIX + forced min version
- Effort: L
- Priority: P1

### AUD-DESK-002
- Severity: High
- Title: Cashier desktop holds a second ledger
- Location: local SQLite + backup AES (POS docs)
- Impact: Stolen PC = sales + catalog + maybe backup password
- Recommendation: Disk encryption requirement; short PIN + remote disable
- Effort: M
- Priority: P1

### AUD-DESK-003
- Severity: Medium
- Title: No crash telemetry
- Location: pubspecs lack sentry_flutter / firebase_crashlytics
- Impact: Production failures invisible
- Effort: S
- Priority: P2

UNKNOWN — Requires runtime verification: installer, auto-start, printer, backup password UX, Windows Defender false positives.
