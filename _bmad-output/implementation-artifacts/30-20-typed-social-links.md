# Story 30.20: Typed social links with icons in the profile header

Status: done (retroactively documented)

## Story

As a member,
I want my social links to be recognised by type and shown with the right icon, displayed in my profile
header,
so that visitors immediately see "Twitch / YouTube / Steam…" instead of a bare URL. Deps: 30.3 (social links
in customization), 30.4 (achievements - the redundant teaser it replaces).

A predefined set of link types (with brand icons) plus a free "other" type, link-type resolution from a URL,
the links surfaced as icons in the header, and removal of the now-redundant achievements teaser.

## Acceptance Criteria

1. A predefined `LINK_TYPES` set (twitch, youtube, x, bluesky, instagram, tiktok, discord, steam, github,
   website) each with a brand icon + label, plus an `other` fallback type.
2. A link is mapped to a type case-insensitively (`resolveLinkType`); an unknown host renders the generic
   "other" icon. Stored links keep their existing `{label, url}` shape - typing is a render concern.
3. Social links render as icons in the **profile header card** (not a separate block lower down).
4. The achievements **teaser** on the profile is retired - the full achievements panel (30.4) already covers
   it, so the duplicate is removed (`ShowcaseWidget` updated accordingly).
5. Gates green: phpstan / php-cs-fixer / `app:architecture:ddd` (for the `ShowcaseWidget` change); typecheck /
   lint / build / jest.

## Tasks / Subtasks

- [x] **frontend:** `social-links.ts` - `LINK_TYPES`, `OTHER_LINK_TYPE`, `resolveLinkType` (case-insensitive),
      `isKnownLinkType`; brand icons via `react-icons/fa6`.
- [x] **frontend:** render typed social-link icons in the header (`player-profile-page.tsx`); editor hints in
      `community-profile-customization-form.tsx`; `community-profile-api.ts` typing.
- [x] **frontend:** move the social links into the header card (`20f1bc4`).
- [x] **api/ Domain:** `ShowcaseWidget` - drop the redundant achievements-teaser widget.
- [x] **Gates** - all green.

## Dev Notes

### Reuse, don't reinvent
- Link typing is presentation-only over the existing `{label,url}` links from 30.3 - no schema change, no
  migration. Brand icons come from `react-icons/fa6` (lucide dropped its brand set).
- Retiring the teaser reuses the full achievements panel (30.4) as the single achievements surface.

### Architecture guardrails
- The stored model is untouched; type resolution happens at render time, so old links keep working.
- `ShowcaseWidget` (Domain) drops the dead widget so an invalid/removed widget can't be selected or rendered
  (it pairs with the `validShowcase()` filter added in the 30.16 review).

### Scope boundaries / deviations
- Fixed type list + "other" - no admin-managed link taxonomy.
- Icons only in the header; the editor still edits plain `{label,url}` rows.

### Project Structure Notes
- Added frontend: `features/community/social-links.ts`.
- Modified frontend: `features/community/community-profile-api.ts`,
  `features/community/community-profile-customization-form.tsx`, `features/players/player-profile-page.tsx`.
- Modified api: `Community/Domain/ShowcaseWidget`.

### References
- Epic §E + stories 30.3/30.4/30.6. [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Typed social links with brand icons, resolved from the URL, shown in the header card; retired the redundant
  achievements teaser (full panel already covers it).
- Implemented in commits `466d653` (typed links + teaser removal) and `20f1bc4` (move into header card).

### Validation Results

- Gates green at merge: php-cs-fixer 0 / phpstan 0 / `app:architecture:ddd` exit 0 / phpunit 0 notices;
  typecheck / lint / build / jest clean.

### File List

**Added (frontend)**
- `frontend/src/features/community/social-links.ts`

**Modified (frontend)**
- `frontend/src/features/community/community-profile-api.ts`
- `frontend/src/features/community/community-profile-customization-form.tsx`
- `frontend/src/features/players/player-profile-page.tsx`

**Modified (api)**
- `api/src/Community/Domain/ShowcaseWidget.php`
