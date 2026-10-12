# Hasim V3 Audit — Stories + Email Contacts + Bulk

**Status:** implemented (Flutter UI in `apps/hasim` + Mobile APIs under `/api/mobile/v1`)

**Branch:** `cursor/merchant-payments-plans-757c`

## Findings
- **EmailContact** already exists (`email_contacts`: name, email, normalized_email, workspace_id). Web CRUD at `workspace.emails.contacts.*`.
- **Customer** is CRM (phone-required); do NOT merge with email address book.
- **CustomerTag / CustomerGroup** exist for CRM; email contacts need their own groups.
- Stories, contact groups, campaigns, and story views are covered by Mobile V1 APIs + Flutter V3 screens.

## Plan
1. Extend `email_contacts` (+ phone, company, job_title, notes, is_favorite, avatar_path, soft deletes).
2. Tables: `email_contact_groups`, pivot, `stories`, `story_views`, `email_campaigns`, `email_campaign_recipients`.
3. Mobile APIs under `/api/mobile/v1` (additive).
4. Bulk send via queued jobs + entitlement check.
5. Flutter: Stories strip, Contacts, Recipient picker, Campaign status.

## Flutter V3 delivered
- Models: `StoryModel`, `EmailContactModel`, `ContactGroupModel`, `EmailCampaignModel`
- Repos: `StoryRepository`, `ContactRepository`, `ContactGroupRepository`, `CampaignRepository`
- Screens: stories strip/viewer/create, contacts list/form/detail, groups + assign members, recipient picker, campaign status, compose upgrade
- Routes: `/stories/create`, `/stories/view`, `/contacts`, `/contacts/form`, `/contacts/:id`, `/contact-groups`, `/email/campaigns/:id`
