# Hasim Chat — Flutter Parity Phase 1

**Date:** 2026-10-05  
**Scope:** Company push registration, catalog lifecycle, product templates, AI skills catalog.  
**Not in this phase:** Commerce, orders, cart, channels, subscription, tax, email attachments, worker push, stories, inbox.  
**Laravel application code:** unchanged. No migration.

Independent Worker remains outside workspace membership. Device registration runs only when a workspace id is stored, which the worker session does not set.

---

## 1. Company Push

`NoopPushService` is removed. `CompanyDevicePushService` uses the existing `PushService` and `DeviceRepository`.

| Step | Behavior |
| --- | --- |
| Register | `POST /devices` with `token`, `provider` (`fcm` on Android, `apns` on iOS), `platform`, `device_name` |
| Stored | Response `id` is kept in preferences with the workspace id and token |
| Duplicate | Same token and workspace skips a second POST |
| Refresh | A new token revokes the stored id, then registers again |
| Logout | `DELETE /devices/{id}` while the workspace header is still the company workspace, then local clear |
| Workspace switch | Revoke on the old workspace, then register on the new one |
| Empty token | No request |
| Web / desktop | No request |
| Worker | No workspace id, so no request |

Production token source is `UnconfiguredNativePushTokenSource`. This build has no FCM/APNs SDK, so `currentToken()` is null and nothing is posted. Tests inject a real token and prove the HTTP cycle.

Push tap routing reuses in-app keys only (`conversation_id`, `email_id`, `story_id`, `type`). There is no OS listener until a native SDK exists. `SendPushNotificationJob` still logs that the FCM HTTP client is not implemented, so Laravel will not deliver a push even after a token is stored.

## 2. Catalog lifecycle

Inside the existing catalog hub, **حالة الكتالوج** calls the transition routes. It does not send `is_active`.

| Action | Endpoint | Shown when status is not |
| --- | --- | --- |
| نشر | `POST /catalog/settings/publish` | `published` |
| إرجاع إلى مسودة | `POST /catalog/settings/draft` | `draft` |
| إيقاف | `POST /catalog/settings/pause` | `paused` |
| أرشفة | `POST /catalog/settings/archive` | `archived` |

Laravel’s service accepts any of these four from any status. Pause and archive ask for confirmation. A second tap while a transition is in flight reuses the same request. Success replaces local settings from the response. Failure leaves the previous status and shows the API error.

Permission is `ChatCatalogAccess::canManageSettings` (owner, admin, manager, or `commerce.catalog.settings.manage`). A 403 is shown. The panel is hidden for a non-company session. Workers are not granted catalog access.

## 3. Product templates

Hub entry **قوالب المنتجات**.

| Supported | Contract |
| --- | --- |
| List | `GET /catalog/product-templates` |
| Open | Row uses the list payload. `GET /catalog/product-templates/{id}` exists on the repository and is not required for the list screen |
| Create | `POST /catalog/product-templates` with `name` |
| Clone | `POST /catalog/product-templates/{id}/clone` with `name` |
| Assign | `PUT /catalog/{item}/product-template` with `product_template_id` only |

Assign does not send product fields. There is no edit or delete button. Laravel has no update or destroy route for templates.

## 4. AI skills catalog

`GET /ai/skills` is the catalog. The sheet shows `name`, `description`, and `category`. It does not show fingerprint, tools, or settings validation.

There is no separate detail route. Opening a skill shows the list fields.

Assignment and removal are one `PATCH /ai/employees/{id}` body:

```json
{ "skills": [{ "key": "...", "settings": {} }] }
```

Laravel replaces the employee skill list when `skills` is present. Unchecking a skill omits its key. This is company AI management (`canManageAiSettings`). It is not worker capabilities and it does not call AiRuntime.

## 5. Laravel changes

None.

## 6. Flutter files

- `apps/hasim/lib/push/push_service.dart`
- `apps/hasim/lib/push/push_token_source.dart`
- `apps/hasim/lib/core/di/providers.dart`
- `apps/hasim/lib/core/storage/prefs_store.dart`
- `apps/hasim/lib/features/settings/data/session_repository.dart`
- `apps/hasim/lib/features/auth/providers/auth_controller.dart`
- `apps/hasim/lib/features/catalog/providers/chat_catalog_settings_controller.dart`
- `apps/hasim/lib/features/catalog/presentation/catalog_lifecycle_panel.dart`
- `apps/hasim/lib/features/catalog/presentation/catalog_templates_screen.dart`
- `apps/hasim/lib/features/catalog/presentation/chat_catalog_settings_screen.dart`
- `apps/hasim/lib/features/catalog/data/chat_catalog_template.dart`
- `apps/hasim/lib/features/catalog/data/chat_catalog_repository.dart`
- `apps/hasim/lib/features/settings/data/ai_employee_model.dart`
- `apps/hasim/lib/features/settings/data/plan_channel_repository.dart`
- `apps/hasim/lib/features/settings/providers/ai_employees_controller.dart`
- `apps/hasim/lib/features/settings/presentation/ai_skills_sheet.dart`
- `apps/hasim/lib/features/settings/presentation/ai_employees_tab.dart`
- `apps/hasim/lib/features/settings/presentation/ai_settings_screen.dart`
- `apps/hasim/lib/router/notification_deep_link.dart`
- Tests: `company_device_push_test.dart`, `phase1_contract_test.dart`, `catalog_lifecycle_test.dart`, `catalog_templates_test.dart`, `ai_skills_sheet_test.dart`
- `story_repository_test.dart`: avatar URL assertion follows `AppConfig.resolvePublicUrl` so it matches the configured host

Commerce Flutter was not restored.

## 7. Tests

Focused Phase 1 tests passed (push lifecycle, device/template/skills/lifecycle contracts, lifecycle UI, templates UI, skills UI).

Regression run for search, inbox, stories, catalog, AI employees, and settings layout passed after the story URL assertion fix.

Full Flutter suite: **425 passed, 1 failed**. The failure is the existing `inner_chrome_corner_test` (overlay size), not this phase.

No Laravel tests were required.

## 8. Analyze

`flutter analyze` in `apps/hasim`: no issues.

## 9. Remaining gaps after Phase 1

Still open from the master audit, and not started here:

- Email attachment contract and UI
- Native channel connect
- Subscription checkout
- Worker push
- Operator commerce desk
- Tax, intelligence authoring, email accounts, customer writes, branding editor
- Template edit/delete (no Laravel route)
- Live push delivery

## 10. Blockers

1. This app has no FCM/APNs SDK and no Firebase/APNs credentials. A fake token is not sent. Registration is idle until a real token source is added.
2. `SendPushNotificationJob` does not call FCM. Stored tokens would not produce a notification until that sender exists.
3. OS notification taps cannot be wired without that SDK. In-app notification navigation is unchanged.

---

Phase 2 was not started.
