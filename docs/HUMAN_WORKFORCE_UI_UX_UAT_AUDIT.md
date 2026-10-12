# Human Workforce UI/UX UAT Audit

Audit date: 2026-09-27.  
Method: route, controller, and Blade inspection of the implemented worker, company, and platform-admin screens. Backend behavior is taken from the Phase 9 acceptance test and the existing feature tests. No browser session was driven, and no production code was changed.

## 1. Executive Summary

The audit covered the existing web UI for three participants: the independent worker, the company workspace user, and the platform admin.

Production code was not modified. No migrations, routes, or tests were changed.

Overall result: the supported workflows exist as pages and forms, and the backend journey already passes. The worker and platform-admin UIs do not expose that journey from the screens those users land on. A worker who finishes registration stays on the profile page with no link to offers, sessions, Inbox, earnings, or disputes, and no logout. A platform admin nav lists operations and disputes, but not the verification queue or payout administration. Company discovery, offer, activation, capabilities, Inbox assignment, earnings, and participant labels are present once the user is inside those pages.

## 2. Worker Journey

| Step | UI Entry | Expected Behavior | Actual Behavior | Status | Evidence |
| --- | --- | --- | --- | --- | --- |
| Registration | `GET /workers/register` | Worker can open a dedicated registration form and create a platform account. | The page exists, explains that the account is platform-level, and posts to `worker.register.store`. It links to the shared login page. | Pass | `resources/views/workers/register.blade.php` |
| Login / post-login routing | `GET /login`, then `AuthenticatedSessionController` | A worker without a workspace lands on the worker profile. The login page should also point at worker registration. | Workers with no active workspace are redirected to `worker.profile.edit`. The login page links only to company `register`, not `worker.register`. | Gap | `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `resources/views/auth/login.blade.php` |
| Profile completion | `GET /workers/profile` | Professional fields, private identity, and discoverability are labeled and saved. | The form separates professional fields from fields marked خاص. Email verification is required before document upload, and that gate is written on the page. | Pass | `resources/views/workers/profile.blade.php` |
| Skills | Same profile page | Worker can select stable skills. | Checkboxes post to `worker.skills.sync`. | Pass | `resources/views/workers/profile.blade.php` |
| Languages | Same profile page | Worker can select supported languages. | Checkboxes post to `worker.languages.sync`. No proficiency control, matching the backend. | Pass | `resources/views/workers/profile.blade.php` |
| Work experience | Same profile page | Worker can add and maintain experience. | Add and delete are on the page. The update route exists, but there is no edit form. | Gap | `resources/views/workers/profile.blade.php`, `routes/web.php` `worker.experiences.update` |
| Private identity | Same profile page | Legal name, date of birth, identity number, and address are marked private. | Labels say خاص. These fields are not rendered on company discovery or engagement pages. | Pass | `resources/views/workers/profile.blade.php`, `resources/views/workspace/worker-discovery/show.blade.php` |
| Document upload | Profile, documents section | Worker uploads a file after email verification. | The upload form is on the profile page and is disabled until email is verified. | Pass | `resources/views/workers/profile.blade.php` |
| Submit verification | Profile, submit form | Worker can submit a completed profile. | The submit button posts to `worker.verification.submit` and is disabled until email is verified. | Pass | `resources/views/workers/profile.blade.php` |
| Verification pending | Profile status cards | The worker sees a clear pending state. | The page prints the raw `verification_status` and `lifecycle_status` strings. | Gap | `resources/views/workers/profile.blade.php` |
| Verification approved | Profile status cards | The worker sees an approved state. | The raw value `approved` is shown. There is no next-step link to engagements. | Gap | `resources/views/workers/profile.blade.php` |
| Rejected / documents required | Profile status cards | The worker sees the decision and the review reason. | Only the raw status is shown. `rejection_reason` is rendered on the platform review page, not on the worker profile. | Gap | `resources/views/workers/profile.blade.php`, `resources/views/platform/worker-verifications/show.blade.php` |
| Discovery visibility | Worker profile checkbox | The worker can opt in, and understands companies see professional data only. | The checkbox says companies may find the professional profile. | Pass | `resources/views/workers/profile.blade.php` |
| Engagement offers | `GET /workers/engagements` | Offers are reachable after login. | The list shows company name, title, and raw status. The profile page, guest layout, and login redirect do not link here. | Blocked from the landing UI | `resources/views/worker/engagements/index.blade.php`, `resources/views/workers/profile.blade.php` |
| Offer details | `GET /workers/engagements/{id}` | The worker sees the company, title, and that acceptance is not membership. | Those facts are on the page. Status is the raw enum value. | Pass, with unclear status text | `resources/views/worker/engagements/show.blade.php` |
| Accept / reject | Offer detail, offered only | Buttons appear only for an offer. | Accept and reject forms render when status is `offered`. | Pass | `resources/views/worker/engagements/show.blade.php` |
| Active engagement | Offer detail | The worker can see the company and granted capabilities. | Company name, raw status, and capability keys are shown. | Pass | `resources/views/worker/engagements/show.blade.php` |
| Granted capabilities | Offer detail | Granted keys are visible. Missing capabilities are not explained. | Only granted keys are listed. There is no denied-capability explanation. | Gap | `resources/views/worker/engagements/show.blade.php` |
| Session start | `GET /workers/engagements/{id}/sessions` | Start is offered only when work is allowed, including after suspension. | The start button is shown whenever engagement status is `active`. Suspension leaves the engagement active, so the button remains. The server then returns the session error. | Gap | `resources/views/worker/engagements/sessions.blade.php` |
| Session end | Same page | An open session can be ended, with break and resume. | End, break, and resume buttons follow the open segment. The page states that page loads do not create activity. | Pass | `resources/views/worker/engagements/sessions.blade.php` |
| Inbox | Link on the engagement page when read is allowed | The worker can open assigned conversations for that company. | The link is rendered only when `canReadInbox` is true. If it is false, the link is absent and no reason is shown. | Gap | `app/Http/Controllers/Worker/WorkerEngagementController.php`, `resources/views/worker/engagements/show.blade.php` |
| Assigned conversations | `GET /workers/{workspace}/inbox` | Only assigned conversations for that workspace are listed. | The page title includes the company name and says assignment is limited to the active engagement. | Pass | `resources/views/worker/inbox/index.blade.php` |
| Sending a message | Inbox composer | Send is available only with the send capability. | The composer is shown for every open conversation. A missing send capability throws `AuthorizationException`, which the controller does not catch, so the worker gets a 403 instead of an inline explanation. | Gap | `resources/views/worker/inbox/index.blade.php`, `WorkerInboxController.php`, `WorkerEngagementAuthorization.php` |
| Earnings | `GET /workers/engagements/{id}/earnings` | The worker can read periods and lines, not edit them. | The page says records are read-only and shows periods, lines, and an objection form for pending or approved periods. | Pass | `resources/views/worker/engagements/earnings.blade.php` |
| Payout visibility | Same earnings page | The worker can see payout status, not create a payout. | Payout rows show raw status, amount, and currency. There is no payout action. | Pass | `resources/views/worker/engagements/earnings.blade.php` |
| Disputes | Engagement page and `GET /workers/disputes` | The worker can open a dispute and read the public description. | The form says a dispute does not change the engagement or earnings. Review notes are not rendered. | Pass | `resources/views/worker/engagements/show.blade.php`, `resources/views/worker/disputes/show.blade.php` |
| Suspended worker | Profile and session page | The UI says work is blocked because the account is suspended. | Profile shows the raw status `suspended`. The session page still offers start when the engagement is active. | Gap | `resources/views/workers/profile.blade.php`, `resources/views/worker/engagements/sessions.blade.php` |
| Terminated engagement | Session page | The UI says the engagement is terminated, so a new session cannot start. | Any non-active engagement, including paused, completed, and terminated, shows "لا يمكن بدء جلسة جديدة قبل تفعيل الارتباط." | Gap | `resources/views/worker/engagements/sessions.blade.php` |
| Multiple companies | Engagement list | Each card names its company. | The card title is `workspace.name`. Another company's engagement is not given a cross-link. | Pass, once the list is opened | `resources/views/worker/engagements/index.blade.php` |

Worker pages use `x-guest-layout`. That layout has no worker navigation and no logout control.

## 3. Company Journey

| Step | UI Entry | Expected Behavior | Actual Behavior | Status | Evidence |
| --- | --- | --- | --- | --- | --- |
| Login | `GET /login` | Company user enters the workspace. | Login redirects a user with an active workspace to the workspace dashboard. | Pass | `AuthenticatedSessionController.php` |
| Discovery | Top nav "اكتشاف العمال" | Verified, discoverable workers can be found. | `layouts/navigation.blade.php` links to `workspace.worker-discovery.index`. The workspace sidebar does not. | Pass via top nav | `resources/views/layouts/navigation.blade.php` |
| Search and filters | Discovery index | Country, availability, experience, and professional search work. | Those filters are on the form. `skill_key` is accepted by `WorkerDiscoveryRequest` but is not a form field. | Gap | `resources/views/workspace/worker-discovery/index.blade.php` |
| Professional profile | Discovery show | Display name, summary, skills, languages, and experience are shown. | Those professional fields are rendered. A notice says private identity and documents are excluded. | Pass | `resources/views/workspace/worker-discovery/show.blade.php` |
| Privacy boundary | Discovery and engagement show | Identity numbers and documents stay off company pages. | Neither page renders `legal_name`, `identity_number`, document paths, or review notes. | Pass | discovery show, `resources/views/workspace/worker-engagements/show.blade.php` |
| Send offer | Discovery show, or manual id form | A company can offer a discoverable worker. | Discovery show posts to `workspace.worker-discovery.engagements.store`. A separate create form accepts a known profile id. | Pass | `resources/views/workspace/worker-discovery/show.blade.php`, `resources/views/workspace/worker-engagements/create.blade.php` |
| Offer state | Engagement show | Status is visible. | The raw enum value is shown, plus the badge "عامل مستقل". | Pass, with raw status text | `resources/views/workspace/worker-engagements/show.blade.php` |
| Accepted engagement | Engagement show | Activate is available after acceptance. | The activate button renders only when status is `accepted`. | Pass | same file |
| Activate | Engagement show | Company can activate. | The activate form posts to `workspace.worker-engagements.activate`. | Pass | same file |
| Pause / resume | Engagement show | Pause from active, resume from paused. | Those buttons follow the status. | Pass | same file |
| Terminate / complete | Engagement show | Complete from active. Terminate from active or paused with a reason. | Both forms are present. Terminate requires a reason field. | Pass | same file |
| Grant capabilities | Capabilities page | Company can grant a catalog capability to this engagement. | Each active definition has a grant button when `canManage` is true. The page says this is not a workspace-staff permission. | Pass | `resources/views/workspace/worker-engagements/capabilities.blade.php` |
| Revoke capabilities | Capabilities page | Company can withdraw a grant. | A granted row shows سحب. | Pass | same file |
| View active workers | Engagement index and HR participants | The company can return to its workers. | The engagement index exists. It is not in the sidebar. The HR page lists participant type and links to the engagement when an engagement id exists. | Gap | `resources/views/workspace/partials/sidebar-links.blade.php`, `resources/views/workspace/employees/index.blade.php` |
| Inbox access | Workspace conversations | Company staff use the existing Inbox. | Sidebar "المحادثات" opens the company Inbox. Workers are not given that company Inbox. | Pass | `sidebar-links.blade.php` |
| Assignment / handoff | Conversation view | Staff can assign an eligible worker engagement. | A select is filled from `$workerAssignments` and posts `worker_engagement_id`. | Pass | `resources/views/workspace/communication/inbox/conversation-view.blade.php` |
| Message attribution | Conversation list and view | Staff, worker, and AI are labeled separately. | Badges use participant type: عامل مستقل, موظف الشركة, AI Employee. The worker label uses `display_name`, not the account email. | Pass | `conversation-list.blade.php`, `conversation-view.blade.php` |
| Earnings | Engagement earnings page | Company can create a period, add a line, submit, and approve. | Those forms are on `workspace/worker-engagements/earnings.blade.php`. | Pass | that view |
| Payout visibility | Same earnings page and platform payout page | Company can see records. Creating a payout is a platform action. | Company pages show payout totals. The create and status forms are on `platform/worker-payouts/index.blade.php`. | Pass for company visibility | company earnings view, platform payout view |
| Disputes | Engagement show and dispute pages | Company can file and read the public dispute. | The form says the dispute does not change engagement or earnings. Review notes are not on the company show page. | Pass | `workspace/worker-engagements/show.blade.php`, `workspace/worker-disputes/show.blade.php` |
| Operational reporting | Engagement show summaries and platform operations | Company sees counts, not a score. | Engagement show shows session, Inbox, earnings, and payout counts. There is no score. | Pass | `workspace/worker-engagements/show.blade.php` |
| AI coexistence | Inbox and HR list | AI remains a separate participant. | Inbox uses `ai_employee` attribution. HR uses `WorkforceParticipantType::label()`. | Pass | Inbox blades, `WorkforceParticipantType.php` |
| Company staff coexistence | HR list and Inbox | Staff remain `workspace_users`. | HR labels موظف الشركة and does not treat the worker row as staff. | Pass | `resources/views/workspace/employees/index.blade.php` |

## 4. Platform Admin Journey

| Step | UI Entry | Expected Behavior | Actual Behavior | Status | Evidence |
| --- | --- | --- | --- | --- | --- |
| Verification queue | `GET /platform/worker-verifications` | Admin can open the queue from the platform nav. | The queue page exists and filters pending, documents required, rejected, approved, and suspended. The platform nav does not link to it. | Gap | `resources/views/platform/worker-verifications/index.blade.php`, `resources/views/platform/partials/nav.blade.php` |
| Profile review | Verification show | Professional fields and account email are visible to the admin. | Display name, email, country, nationality, experience, availability, and summary are shown. | Pass | `resources/views/platform/worker-verifications/show.blade.php` |
| Private identity review | Same page | Admin can read legal identity. | Legal name, date of birth, identity number, and address are in an admin-only section. | Pass | same file |
| Documents | Same page | Admin can download a document through a protected link. | The page uses a 15-minute signed download route. | Pass | same file |
| Approve | Same page | Admin can approve. | Approve form posts to `platform.worker-verifications.approve`. | Pass | same file |
| Reject | Same page | Admin can reject with a reason. | Reject form requires a reason. | Pass | same file |
| Request documents | Same page | Admin can ask for more documents. | The request-documents form is on the page. | Pass | same file |
| Suspend | Same page | Admin can suspend with a reason. | Suspend form is on the page. | Pass | same file |
| Lifecycle visibility | Queue and show | Status is understandable. | The queue filter uses Arabic labels. The show page prints the raw `verification_status`. | Gap | both verification views |
| Engagement visibility | `GET /platform/worker-operations` | Admin can see engagements across companies. | The operations page lists worker display name, company, raw status, and dates. It is linked from the platform nav. | Pass | `resources/views/platform/worker-operations/index.blade.php`, platform nav |
| Earnings / payout administration | `GET /platform/worker-payouts` | Admin can create and advance a payout record. | The page lists approved periods and payout actions. It is not linked from the platform nav or the operations page. | Gap | `resources/views/platform/worker-payouts/index.blade.php`, platform nav |
| Dispute review | Platform disputes | Admin can move open, under review, resolved, and closed. | Index and show are linked from the nav. Show has the three transition forms. | Pass | `resources/views/platform/worker-disputes/show.blade.php` |
| Review notes privacy | Platform dispute show | Notes stay on the admin page. | `review_notes` is rendered only in the platform dispute view. Worker and company dispute views do not print it. | Pass | platform, worker, and company dispute show views |
| Operational visibility | Operations page | Counts without a productivity score. | The page shows verified, discoverable, active, pending, and suspended counts, plus payout status counts. | Pass | `resources/views/platform/worker-operations/index.blade.php` |

## 5. Cross-Participant Separation

| Boundary | Result | Evidence |
| --- | --- | --- |
| Staff vs independent worker | Inbox and the HR table use participant type, not the display name. Worker cards are labeled عامل مستقل. Staff are موظف الشركة. | `conversation-list.blade.php`, `conversation-view.blade.php`, `workspace/employees/index.blade.php`, `WorkforceParticipantType::label()` |
| Independent worker vs AI employee | AI is labeled AI Employee or موظف AI from `ai_employee`. Worker messages use `worker_engagement_id` and the worker display name. | Inbox blades, `WorkforceInboxAttribution` usage in `worker/inbox/index.blade.php` |
| Workspace isolation | Company engagement, dispute, and Inbox routes are workspace scoped. Worker Inbox is opened with the engagement workspace id. | `routes/web.php`, engagement and dispute views |
| Engagement isolation | Worker list items and Inbox are tied to one company or one engagement. Company B has no link to Company A's engagement. | `worker/engagements/index.blade.php`, `worker/inbox/index.blade.php` |
| Private identity | Shown on the worker profile and the platform review page. Not shown on discovery or company engagement pages. | profile, platform verification show, discovery show |
| Verification documents | Download control exists only on the platform review page, through a signed route. | `platform/worker-verifications/show.blade.php` |
| Message attribution | Company Inbox and worker Inbox both branch on participant type. The worker composer says the message is recorded on the worker account and engagement. | both Inbox views |

## 6. State / Permission UX

| State | Where it appears | How the UI communicates it |
| --- | --- | --- |
| Profile incomplete / registered | Worker profile cards | Raw `lifecycle_status` string. |
| Verification pending | Worker profile cards | Raw `pending_review`. |
| Documents required | Worker profile cards | Raw `documents_required`. The review reason is not shown. |
| Verification rejected | Worker profile cards | Raw `rejected`. The rejection reason is not shown. |
| Verified | Worker profile cards | Raw `approved` / `verified`. |
| Engagement offered | Worker engagement show | Raw `offered`, with accept and reject buttons. |
| Accepted | Company engagement show | Raw `accepted`, with an activate button. |
| Active | Both engagement shows | Raw `active`. Company gets pause, complete, and terminate. Worker gets session start if no session is open. |
| Paused | Company show and worker session page | Company gets resume and terminate. Worker session page uses the generic "activate first" sentence. |
| Completed | Worker session page | Same generic sentence. No company resume button. |
| Terminated | Worker session page | Same generic sentence. |
| Suspended | Worker profile and session page | Raw `suspended` on the profile. Session start stays visible because the engagement can remain `active`. |
| Capability granted | Worker engagement show and company capabilities page | Worker sees granted keys only. Company sees ممنوحة and a withdraw button. |
| Capability denied | Company capabilities page | غير ممنوحة when management is closed. The worker Inbox link is omitted with no sentence explaining the missing read capability. |
| Dispute open / under review / resolved / closed | Dispute pages | Raw status values on worker and company pages. Platform dispute show exposes the matching action for the current status. |

Idle session segments exist in the backend. The worker session page only offers break from an active segment and resume from a break. That matches the buttons that were built. No separate idle control is exposed.

## 7. Findings

### BLOCKER — Worker landing UI does not lead to the rest of the journey

- Participant: Independent worker
- Workflow: Offers, sessions, Inbox, earnings, disputes, logout
- Current behavior: Registration and login leave the worker on `workers/profile.blade.php` inside the guest layout. That page has no link to `/workers/engagements` and no logout. Engagement, session, Inbox, and earnings pages link to each other only after the worker already knows one of those URLs.
- Expected behavior: After login, the worker can see offers and move among profile, engagements, sessions, Inbox, earnings, and disputes, and can log out.
- Evidence: `resources/views/workers/profile.blade.php`, `resources/views/layouts/guest.blade.php`, `AuthenticatedSessionController.php` lines 46–47
- Fix direction: Add a worker account navigation shell with those links and logout. Do not send the worker through the company sidebar.

### HIGH — Primary login does not offer worker registration

- Participant: Independent worker
- Workflow: Registration
- Current behavior: `auth/login.blade.php` links to company `register`. Worker registration is only at `/workers/register`, which itself links back to login.
- Expected behavior: The login screen points workers to `worker.register` without sending them through company signup.
- Evidence: `resources/views/auth/login.blade.php`, `resources/views/workers/register.blade.php`
- Fix direction: Add a worker-registration link on the login page.

### HIGH — Platform nav omits verification and payouts

- Participant: Platform admin
- Workflow: Verification queue, document review, approve, reject, request documents, suspend, payout records
- Current behavior: `platform/partials/nav.blade.php` links operations and disputes. It does not link `platform.worker-verifications.index` or `platform.worker-payouts.index`. The operations page does not link them either.
- Expected behavior: An admin can open the queue and payout desk from the platform navigation.
- Evidence: `resources/views/platform/partials/nav.blade.php`, `resources/views/platform/worker-operations/index.blade.php`
- Fix direction: Add those two links to the platform nav.

### HIGH — Rejection and document-request reasons never reach the worker

- Participant: Independent worker
- Workflow: Rejected and documents-required verification
- Current behavior: The worker profile prints `verification_status`. `rejection_reason` is rendered only on the platform review page.
- Expected behavior: The worker sees the decision in plain language and the reason the platform recorded.
- Evidence: `resources/views/workers/profile.blade.php`, `resources/views/platform/worker-verifications/show.blade.php`
- Fix direction: Show the latest worker-visible decision text on the profile. Keep internal review notes off that page.

### HIGH — Inbox composer ignores a missing send capability

- Participant: Independent worker
- Workflow: Sending a message
- Current behavior: The engagement page hides the Inbox link unless read is allowed. Inside the Inbox, every open conversation shows a send form. `sendMessage` calls `WorkerEngagementAuthorization::authorize()`, which throws `AuthorizationException`. `storeMessage` only catches `WorkerInboxException`, so the denial is a 403 page rather than the flash error used for other Inbox failures.
- Expected behavior: Without `inbox.messages.send`, the composer is hidden and the page says the engagement does not include send permission.
- Evidence: `resources/views/worker/inbox/index.blade.php`, `app/Http/Controllers/Worker/WorkerInboxController.php`, `app/Services/Worker/WorkerEngagementAuthorization.php`
- Fix direction: Pass a send-capability flag into the Inbox view and render an explanation instead of the form.

### HIGH — Suspension still looks like permission to start work

- Participant: Independent worker
- Workflow: Suspended worker, session start
- Current behavior: Suspension changes the profile, not the engagement status. The session page shows "بدء جلسة العمل" whenever the engagement is `active`.
- Expected behavior: A suspended account hides start and says the block comes from platform suspension.
- Evidence: `resources/views/worker/engagements/sessions.blade.php`, `WorkerProfileService::suspend`
- Fix direction: On the session page, treat `verification_status = suspended` as its own blocked state.

### MEDIUM — Non-active session copy is one sentence for every blocked status

- Participant: Independent worker
- Workflow: Paused, completed, and terminated engagements
- Current behavior: The only blocked sentence is "لا يمكن بدء جلسة جديدة قبل تفعيل الارتباط."
- Expected behavior: Paused, completed, and terminated each say why start is unavailable.
- Evidence: `resources/views/worker/engagements/sessions.blade.php`
- Fix direction: Branch the empty state on `WorkerEngagementStatus`.

### MEDIUM — Missing Inbox read permission is silent

- Participant: Independent worker
- Workflow: Inbox access
- Current behavior: `canReadInbox` false removes the link.
- Expected behavior: The engagement page says Inbox is unavailable until `inbox.messages.read` is granted on this engagement.
- Evidence: `resources/views/worker/engagements/show.blade.php`
- Fix direction: Render the link as disabled text with that reason.

### MEDIUM — Discovery cannot filter by the skills the profile shows

- Participant: Company user
- Workflow: Worker search
- Current behavior: The profile shows skills. The search form has text, country, availability, and experience level. The request already allows `skill_key`.
- Expected behavior: The search form can filter by the existing skill catalog.
- Evidence: `resources/views/workspace/worker-discovery/index.blade.php`, `WorkerDiscoveryRequest.php`
- Fix direction: Add a skill select bound to `skill_key`.

### MEDIUM — Most lifecycle labels are raw enum values

- Participant: Worker, company, platform admin
- Workflow: Status understanding
- Current behavior: Worker and company engagement badges, session rows, earning statuses, payout statuses, and the platform operations table print values such as `offered`, `active`, and `pending_review`. The verification queue filter is the exception and uses Arabic labels.
- Expected behavior: Each supported status has one Arabic label and, where useful, one sentence about what the user can do next.
- Evidence: engagement, session, earnings, dispute, and operations Blade files
- Fix direction: Map the existing enums to labels in the views. Do not add new states.

### MEDIUM — Company engagement list is off the main workspace navigation

- Participant: Company user
- Workflow: Returning to active workers and disputes
- Current behavior: Sidebar links HR and conversations, not `workspace.worker-engagements.index` or `workspace.worker-disputes.index`. Top nav links discovery only. After the first offer, the user can move between engagement subpages.
- Expected behavior: Discovery, engagements, and disputes are visible in the company navigation.
- Evidence: `resources/views/workspace/partials/sidebar-links.blade.php`, `resources/views/layouts/navigation.blade.php`
- Fix direction: Add those links beside discovery.

### MEDIUM — Experience can be deleted and re-added, not edited

- Participant: Independent worker
- Workflow: Work experience
- Current behavior: The profile has a create form and a delete button. `worker.experiences.update` has no form.
- Expected behavior: An existing experience row can be edited.
- Evidence: `resources/views/workers/profile.blade.php`
- Fix direction: Add an edit form that posts to the existing update route.

### LOW — Capability keys are shown to workers without the Arabic catalog name

- Participant: Independent worker
- Workflow: Granted capabilities
- Current behavior: The worker sees keys such as `inbox.messages.read`. The company capabilities page shows `display_name` and the key.
- Expected behavior: The worker sees the same display name the company sees.
- Evidence: `resources/views/worker/engagements/show.blade.php`, `resources/views/workspace/worker-engagements/capabilities.blade.php`
- Fix direction: Render the capability display name on the worker engagement page.

## 8. Missing Coverage

Implemented and exposed:

- Worker registration, profile, skills, languages, private identity, document upload, verification submit
- Company discovery, professional profile, offer, activate, pause, resume, complete, terminate
- Capability grant and revoke
- Company Inbox assignment with separate staff, worker, and AI labels
- Worker Inbox for an assigned conversation, once the worker can open that URL
- Session start, end, break, and resume
- Company earning periods and worker read-only earnings
- Worker and company disputes, platform dispute transitions
- Platform verification actions and signed document download
- Platform operations counts
- HR participant list with type labels

Implemented, but weakly exposed:

- Worker engagements, sessions, earnings, disputes, and Inbox, because the profile landing page does not link to them
- Platform verification queue and payout desk, because the platform nav does not link to them
- Skill search, because the backend filter is not on the discovery form
- Experience update, because only create and delete are on the profile
- Rejection and document-request reasons, because they stay on the admin page

Backend exists, UI missing:

- None of the supported worker operations lack a page. The gaps are navigation, labels, and blocked-state copy.

Intentionally unsupported, not treated as defects:

- Customer, catalog, commerce, and report capabilities have no worker screens
- No worker Flutter app and no worker dashboard
- No payout provider checkout
- No automatic commission, ranking, matching, productivity score, legal or tax reporting, or scheduled shifts
- Empty Jordan and Saudi document requirements, so the profile correctly says no mandatory document type is configured
- Language proficiency is not a field

## 9. Recommended Next Implementation Scope

Do not implement these in this audit.

1. Worker account chrome. Add navigation and logout on the worker pages, including a link from the profile to engagements. Add a worker-registration link on the login page.
2. Platform nav. Link the verification queue and the payout desk.
3. Blocked-state copy. Show suspension, pause, completion, termination, rejection reason, and missing read or send capability in place of the current generic or silent states.
4. Labels and the remaining form gaps. Replace raw status strings with the existing Arabic labels, show capability display names to workers, add the skill filter, and add the experience edit form.

## Validation

No production code, tests, routes, or database objects were changed.

Checks used:

- Route inspection of `routes/web.php` and `routes/platform.php`
- Controller inspection for login redirect, Inbox send, session errors, and engagement Inbox visibility
- Blade inspection of the worker, company, Inbox, and platform views listed above

Existing feature tests were not re-run. This audit did not need a failing test to establish the UI gaps, because the gaps are in navigation and copy rather than in a broken assertion.
