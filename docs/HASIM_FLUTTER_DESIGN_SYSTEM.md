# Hasim Flutter — Design System

Official UI/UX spec for `apps/hasim`. **All new screens and components must follow this document.**

## Principles

- Arabic **RTL**, desktop-first, fully responsive
- Modern SaaS dashboard: clean, premium, not crowded
- **Consistency over one-off polish**
- Frontend only — no API / backend changes

## Tokens (`lib/core/theme/hasim_tokens.dart`)

| Token | Usage |
| --- | --- |
| `primary` / `accent` | Turquoise actions, active nav |
| `primaryLight` / `mintSoft` | Active backgrounds |
| `background` / `pageBg` | Page canvas |
| `card` / `cardBg` | Surfaces |
| `line` / `lineColor` | Borders |
| `ink` / `inkColor` | Primary text |
| `muted` / `mutedColor` | Secondary text |
| `danger` / `dangerSoft` | Logout, destructive |
| `spaceXs`–`space2xl` | 8px grid (6, 10, 16, 24, 32, 40) |
| `radiusMd` (14) | Cards, inputs (default DS) |
| `radiusCard` (20) | Legacy large cards |
| `softShadow` | Card elevation |

Do **not** hard-code random hex colors in features — use `HasimTokens` or `Theme.of(context).colorScheme`.

## Typography

- Font: **Cairo** via `AppTheme` / `GoogleFonts.cairo`
- Page title: `HasimPageHeader` (26px, w800)
- Section title: `titleMedium`, w700
- Body: `bodyLarge` / `bodyMedium`
- Secondary: `mutedColor`

## Layout shell

- **Nav rail** (desktop): `HasimNavRail` + `HasimAdaptiveScaffold` — settings gear at bottom
- **Bottom nav** (mobile): 3 tabs — المحادثات، البريد، التحديثات
- **Settings split** (wide): list panel + detail pane (`SettingsScreen`)

## Mobile-only UI (`width < 700`, `HasimLayout.isCompact`)

WhatsApp-like simplicity: mint page bg, white row cards, turquoise icon tiles, light borders, RTL chevrons.

| Component | Path | Use |
| --- | --- | --- |
| `HasimMobileListRow` | `lib/core/widgets/mobile/hasim_mobile_list_row.dart` | Tappable settings/more rows |
| `HasimMobileListShell` | same | List padding + page bg |
| `HasimMobileScaffold` | `lib/core/widgets/mobile/hasim_mobile_scaffold.dart` | Sub-page app bar (optional `appBarBottom` for tabs) |

Rules:

- Gate with `HasimLayout.isCompact(context)` — **never** change desktop/tablet split layouts.
- `HasimSettingsBody(mobileLayout: true)` for full-width settings forms on phone.
- Do not add menu items or routes — restyle existing content only.

## Reusable components (`lib/core/widgets/`)

| Component | Use |
| --- | --- |
| `HasimCard` | White surface, border, soft shadow |
| `HasimPageHeader` | Title + optional subtitle |
| `HasimSettingsBody` | Settings detail scroll, max width 720 |
| `HasimFormField` | Labels, icons, focus border |
| `HasimPrimaryButton` / `HasimSecondaryButton` | Actions |
| `HasimTextLinkButton` | Text actions (e.g. revoke sessions) |
| `HasimBadge` | Status pills |
| `EmptyState` | Empty lists |
| `HasimSearchBar` | Search in inbox/settings |

## Settings pages

1. Embedded in split: header in `_EmbeddedDetail`, body uses `HasimSettingsBody` + `HasimCard`(s)
2. Same card/button/input patterns on every settings sub-page
3. Reference implementations: **Profile**, **Security & sessions**, **Notification preferences**

## Chat

- Wallpaper slightly softened; composer + attachment popover per product spec
- Attachment menu: `ChatAttachmentMenuPanel` — compact, RTL, colored icons, arrow to attach button

## Checklist for new UI

- [ ] RTL verified (icons + text order)
- [ ] Uses `HasimTokens` spacing/colors
- [ ] Uses `HasimCard` / theme buttons / `HasimFormField` where applicable
- [ ] Page uses `HasimPageHeader` or shell header
- [ ] No new one-off sidebar or button styles
- [ ] Responsive: no horizontal overflow on 390px width

## Screens audit (37 routes)

Apply DS incrementally to any screen still using raw `ListTile` / default `Scaffold` without tokens:

Auth (login, splash, …), workspace picker, conversations, chat, email, updates/stories, catalog, contacts, customers, notifications, search, settings/*, more (legacy redirect).

When touching a screen, migrate it to shared components — do not restyle in isolation.
