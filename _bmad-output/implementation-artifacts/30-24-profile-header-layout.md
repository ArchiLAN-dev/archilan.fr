# Story 30.24: Profile header card layout

Status: done (retroactively documented)

## Story

As a visitor,
I want the profile header to present the avatar, name and badges in a clear, polished arrangement,
so that identity reads well against the animated banner. Deps: 30.22 (animated banner), 30.17 (badges),
30.23 (avatar frame).

The header is reworked so the avatar and name straddle the bottom of the banner (left-aligned), with the
recognition badges tucked directly under the name beside the avatar.

## Acceptance Criteria

1. The avatar and display name straddle the banner/details boundary (the avatar half over the banner),
   left-aligned — not a fully centered card.
2. The recognition badges (30.17) sit directly under the name, beside the avatar, and stay readable.
3. The details block (tagline, "Membre depuis", level bar, social-link icons) and relationship actions sit
   below, unchanged in content.
4. Layout is responsive (avatar/offset/typography scale at the `sm` breakpoint).
5. Gates green: typecheck / lint / build / jest (frontend-only change).

## Tasks / Subtasks

- [x] **frontend:** rework the header in `player-profile-page.tsx` — avatar + name straddle the banner,
      badges under the name beside the avatar, details below.
- [x] **Gates** — typecheck / lint / build / jest green.

## Dev Notes

### Reuse, don't reinvent
- Layout-only over the existing header pieces (avatar, name, badges, details from 30.17/30.22/30.23) — no new
  data, no API change.

### Architecture guardrails
- Frontend presentation only; the page stays a pure render component.

### Scope boundaries / deviations
- Reached through several iterations on user feedback (centered card → left layout straddling the banner →
  badge placement under the name). A vertical-centering attempt (`50ac0cd`) was **reverted** (`467be36`); the
  final state is the left layout with badges under the name. Only `player-profile-page.tsx` changed.

### Project Structure Notes
- Modified frontend: `features/players/player-profile-page.tsx`.

### References
- Epic §A/§E + stories 30.17/30.22/30.23. [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Profile header reworked: avatar + name straddle the banner (left-aligned), badges under the name beside the
  avatar, details below.
- Iterated across `fd7df98` → `467be36`; the vertical-centering step (`50ac0cd`) was reverted (`467be36`).

### Validation Results

- Gates green at merge: typecheck / lint / build / jest clean (no API change).

### File List

**Modified (frontend)**
- `frontend/src/features/players/player-profile-page.tsx`
