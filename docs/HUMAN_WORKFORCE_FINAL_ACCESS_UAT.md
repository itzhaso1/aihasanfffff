# Human Workforce Final Access UAT

Verification only. No feature work.

## 1. Summary

- Test date: 2026-09-27
- Participants: independent worker (verified profile, active engagement), company workspace owner, platform admin, AI Employee (separation only)
- Scope: Web Chat-only boundary, Chat capabilities, Finance/POS denial, company workforce access, platform worker administration, worker mobile API, AI separation, multi-company Chat isolation, suspension and termination
- Production code changed: no
- Test files added: `tests/Feature/Worker/WorkerFinalAccessUatTest.php`
- The new tests do not set `bypassIndependentWorkerWebBoundary`. Some older domain tests still set that flag so they can call worker Web controllers. Those tests are cited only for company, platform, and lifecycle behavior. Worker Web denial in this report comes from tests that leave the middleware on.

Commands:

| Command | Result |
| --- | --- |
| `php artisan test --compact tests/Feature/Worker/WorkerFinalAccessUatTest.php` | 6 passed, 114 assertions |
| `php artisan test --compact tests/Feature/Worker` | 132 passed, 1058 assertions |
| `php artisan test --compact tests/Feature/Auth/AuthenticationTest.php` | 4 passed, 22 assertions |
| `php artisan test --compact tests/Feature/AI/Employees/AiEmployeeApiTest.php --filter=test_owner_can_create_list_show_and_update_employees\|test_other_workspace_employees_are_not_found_on_every_route\|test_members_without_ai_permission_are_forbidden` | 3 passed, 52 assertions |
| `vendor/bin/pint --dirty --format agent` | passed |

The worker suite includes the new UAT file, `WorkerWebChatBoundaryTest`, `WorkerInboxTest`, `WorkerMobileApiTest`, and the company/platform workforce tests.

## 2. Worker Web Access Matrix

Independent worker. HTML denial is a redirect to the named route `worker.inbox.home` (`GET /workers/inbox`). JSON denial is HTTP 403. Forbidden responses did not contain the private identity marker `Leak Identity 99188` or the mutation marker `UAT Secret 77120`.

| Area | Worker Expected | Actual | Status |
| --- | --- | --- | --- |
| Hasim Chat | Login lands on Chat. Assigned conversation opens. | `POST /login` redirect `worker.inbox.home`. `GET worker.inbox.home` 200, body contains المحادثات, تسجيل الخروج, and the assigned customer name. `GET worker.inbox.show` 200. | PASS |
| Profile | No Web profile page or update. | `GET worker.profile.edit` redirect. `PATCH worker.profile.update` redirect. `PATCH` JSON 403. `display_name` stayed `Mutation Worker`. | PASS |
| Skills, languages, experience | No Web write. | `PUT worker.skills.sync`, `PUT worker.languages.sync`, `POST worker.experiences.store` redirect. | PASS |
| Verification and documents | No Web submit or upload. | `POST worker.documents.store` and `POST worker.verification.submit` redirect. | PASS |
| Private identity | Identity values do not render. | Profile, engagements, sessions, earnings, and disputes redirects did not contain `Leak Identity 99188`. Chat home did not contain it or the engagement job title. | PASS |
| Engagements | No engagement management UI. | `GET` index and show redirect. `POST worker.engagements.accept` redirect. | PASS |
| Sessions | No session page or start. | `GET worker.engagements.sessions.show` redirect. `POST` start redirect. `worker_work_sessions` count stayed 0. | PASS |
| Earnings | No earnings dispute page. | `GET worker.engagements.earnings.show` redirect. `POST worker.engagements.earnings.dispute` redirect. | PASS |
| Disputes | No dispute pages or create. | `GET worker.disputes.index` redirect. `POST worker.engagements.disputes.store` redirect. No `worker_disputes` row for that engagement. | PASS |
| Finance | No finance Web path. | `workspace.finance.dashboard`, `workspace.finance.employees.index`, and `workspace.finance.invoices.index` redirect to Chat. | PASS |
| Cashier/POS | No POS Web path. | `workspace.pos.dashboard`, `workspace.pos.cashier.index`, `workspace.pos.menu.index`, and `workspace.pos.kitchen.index` redirect to Chat. | PASS |
| Platform Admin | No platform pages. | Verification, operations, payouts, and disputes index routes redirect to Chat. | PASS |
| Company Workforce | No company pages. Chat nav has no company links. | Discovery, company engagements, and `workspace.employees.index` redirect to Chat. Chat HTML did not contain اكتشاف العمال, المالية, finance employees, POS cashier, platform verification, or the worker profile URL. | PASS |

Evidence: `WorkerFinalAccessUatTest` and `WorkerWebChatBoundaryTest` inside the 132 passing worker tests.

Chat home after login uses the named route `worker.inbox.home`. The worker response uses the Chat account nav, not the company sidebar.

## 3. Capability Matrix

| Capability | Granted | Expected | Actual | Status |
| --- | --- | --- | --- | --- |
| `inbox.messages.read` | Yes, with send | Open the assigned conversation and its messages. | `GET worker.inbox.show` 200 and shows the customer. Send persists `user_id` of the worker and `worker_engagement_id` of that engagement. `ai_generated` is false. | PASS |
| `inbox.messages.read` | No | Direct conversation URL does not return message content. | `GET worker.inbox.show` 404. Body does not contain `Hidden customer message 99188`. | PASS |
| `inbox.messages.send` | No, read is granted | Read is allowed. Composer is absent. Direct POST does not persist. | `GET` 200, body contains the no-send sentence and does not contain `name="content"`. `POST worker.inbox.messages.store` 403. `messages.content = Not allowed` is absent. | PASS |
| `inbox.messages.send` | Yes, then engagement terminated or worker suspended | Send is rejected and nothing new is stored. | Suspended and terminated `POST` returned 404. Those contents were absent from `messages`. | PASS |

Evidence: `WorkerWebChatBoundaryTest::test_chat_capability_authorization_still_blocks_read_and_send`, `WorkerInboxTest::test_worker_can_only_read_a_conversation_assigned_to_their_active_engagement`, `WorkerInboxTest::test_worker_cannot_send_without_the_send_capability`, `WorkerInboxTest::test_worker_message_is_attributed_to_the_user_and_immutable_engagement_context`, `WorkerFinalAccessUatTest::test_suspended_worker_cannot_open_sessions_or_send_chat`, `WorkerFinalAccessUatTest::test_terminated_engagement_cannot_send_and_another_company_stays_isolated`.

A client field `ai_generated=true` on the worker send request was stored as false.

## 4. Company Regression

Company owner, including an owner who also has a worker profile.

| Action | Expected | Actual | Status |
| --- | --- | --- | --- |
| Login | Company dashboard | `POST /login` redirect `workspace.dashboard` | PASS |
| Discovery | Page renders, including اكتشاف العمال | `GET workspace.worker-discovery.index` 200 and contains اكتشاف العمال | PASS |
| Engagement list | Page renders | `GET workspace.worker-engagements.index` 200 | PASS |
| Offer and engagement lifecycle | Existing company flows still pass | `WorkerDiscoveryTest`, `WorkerEngagementTest`, `WorkerWorkforceAcceptanceTest` passed in the worker suite | PASS |
| Capability grant and revoke | Company manager can grant and revoke | `WorkerEngagementCapabilityTest` passed | PASS |
| Inbox assignment | Company can assign an eligible worker | `WorkerInboxTest::test_authorized_company_user_can_assign_an_eligible_worker` passed | PASS |
| Plain user login | Unchanged | `AuthenticationTest` 4 passed | PASS |

The Chat-only middleware did not redirect these company requests.

## 5. Platform Regression

Platform admin on the `platform_admin` guard.

| Action | Expected | Actual | Status |
| --- | --- | --- | --- |
| Verification queue | Page renders | `GET platform.worker-verifications.index` 200 | PASS |
| Document review and approve/reject | Existing platform review still passes | `WorkerFoundationTest::test_only_platform_admin_can_review_and_private_documents_are_not_public` and `WorkerVerificationRequirementTest::test_documents_stay_private_while_platform_admin_can_review` passed | PASS |
| Suspension | Platform suspend succeeds. Worker Web session page does not render. | `POST platform.worker-verifications.suspend` redirect. Worker then received Chat redirects, not the session form. | PASS |
| Operations | Platform operations remain available | `WorkerOperationalReportingTest::test_platform_operations_require_platform_guard_and_paginate_engagements` passed | PASS |
| Payouts | Platform payout administration remains available | `WorkerPayoutTest` passed | PASS |
| Disputes | Platform dispute administration remains available | `WorkerComplianceTest::test_platform_review_lifecycle_is_controlled_and_audited` and `test_platform_dispute_routes_require_platform_guard` passed | PASS |

An independent worker requesting those same platform URLs was redirected to Chat.

## 6. Mobile API Regression

`/api/mobile/v1/worker` is outside the Web middleware.

| Action | Expected | Actual | Status |
| --- | --- | --- | --- |
| Authenticated profile | 200 with the worker display name | `GET mobile.v1.worker.profile` 200, `data.display_name` matches | PASS |
| Engagement and disputes | Own engagement is readable. Private identity is not the Web page. | `WorkerMobileApiTest::test_authenticated_worker_can_read_own_profile_engagement_and_not_private_identity` passed. Same user `GET worker.profile.edit` redirects to Chat. | PASS |
| Inbox capability | Missing send/read is denied on the API | `WorkerMobileApiTest::test_inbox_capability_is_enforced_on_the_worker_api` passed | PASS |
| Unauthenticated | 401 | `GET mobile.v1.worker.profile` 401 | PASS |
| Other worker or company | Not found | `WorkerMobileApiTest::test_worker_cannot_read_another_worker_or_company_engagement` passed | PASS |

## 7. AI Separation

| Check | Expected | Actual | Status |
| --- | --- | --- | --- |
| Worker send | Human user, engagement id, not an AI message | Message `user_id` is the worker, `worker_engagement_id` is the engagement, `ai_generated` is false | PASS |
| AI Employee rows | Worker send does not create an AI Employee | `ai_employees` count stayed 0. No row with `created_by` equal to the worker. | PASS |
| AI Employee API | Workspace AI Employee CRUD and isolation stay in place | Owner create/list/show/update passed. Another workspace is not found. A member without AI permission is forbidden. 3 tests, 52 assertions. | PASS |

`WorkerInboxService::sendMessage` writes the conversation message with `ai_generated` false and does not construct `AiRuntime`. No AI Employee file was changed in this verification.

## 8. Findings

No production defect was proven.

Notes that are not defects:

- Older worker domain tests set `bypassIndependentWorkerWebBoundary`. That flag is off by default and is off in `WorkerWebChatBoundaryTest`, `WorkerInboxTest`, `WorkerMobileApiTest`, and `WorkerFinalAccessUatTest`.
- `WorkerComplianceTest` still renders the suspended session page because that class bypasses the Web middleware. With the middleware on, `WorkerFinalAccessUatTest` proved the same suspended worker is redirected to Chat and cannot start a session or send.

## 9. Final Status

PASS

Multi-company: Company A termination blocked send on Company A's conversation. Company B's conversation still opened and accepted a send attributed to Company B's engagement. Company A's customer name was not on Company B's page. `WorkerInboxTest::test_same_worker_has_isolated_inboxes_for_multiple_engagements` and `test_worker_cannot_read_another_workspace_or_workers_conversation` also passed.

Suspension: platform suspend kept the engagement active, redirected the worker session URL to Chat, returned 404 for the conversation and the send, and did not store `Blocked while suspended`.

Termination: company terminate from an active engagement returned 404 for a later send, and that reply was not stored.
