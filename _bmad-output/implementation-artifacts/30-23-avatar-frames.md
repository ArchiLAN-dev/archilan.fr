# Story 30.23: Decorative avatar frames

Status: done (retroactively documented)

## Story

As a member,
I want a decorative frame around my profile avatar,
so that I can personalise it (coloured rings, glows, and a couple of animated "premium" effects). Deps: 30.2
(avatar resolution), 30.3 (profile customization).

A persisted `avatar_frame` choice from a curated catalog (solid colour rings, neon glows, and animated
holographic / gold-shimmer / spectral effects), rendered around the avatar on the public profile and the
editor, honouring reduced-motion.

## Acceptance Criteria

1. `AvatarFrame` (Domain) defines the valid frame keys: gold, silver, bronze, crimson, emerald, sapphire,
   violet, neon_pink, neon_cyan, neon_green, toxic, holographic, gold_shimmer, spectral. An unknown key is
   rejected.
2. `CommunityProfile` gains a nullable `avatar_frame` (VARCHAR 32); `customize()` accepts it; migration
   `Version20260618190000` adds it (reversible).
3. The avatar renders inside the chosen frame on the public profile and in the editor preview; no frame =
   plain avatar. The frame never breaks the initials fallback.
4. Animated frames (holographic, gold_shimmer, spectral) are CSS/SVG-driven and respect
   `prefers-reduced-motion`.
5. Gates green: phpstan / php-cs-fixer / phpunit (0 notices) / `app:architecture:ddd`; typecheck / lint /
   build / jest.

## Tasks / Subtasks

- [x] **api/ Domain:** `AvatarFrame` (valid keys) + `CommunityProfile` `avatar_frame` column + `customize()`.
- [x] **api/ Application:** `UpdateCommunityProfile` persists it; `CommunityProfileView` exposes it.
- [x] **api/ Migration:** `Version20260618190000` (`avatar_frame`).
- [x] **api/ tests:** `CommunityProfileCustomizationTest` covers set / clear / invalid.
- [x] **frontend:** `avatar-frame.tsx` + `avatar-frame.module.css` + `avatar-frames.ts` (catalog); wired into
      `profile-avatar.tsx`, the editor, and the profile/API typing.
- [x] **Gates** - all green.

## Dev Notes

### Reuse, don't reinvent
- The frame wraps the existing `ProfileAvatar` (30.2) - it composes around the avatar/initials fallback
  rather than replacing it.
- `avatar_frame` is one more field on the customization aggregate, mutated only via `customize()` (AC-D5).

### Architecture guardrails
- Valid keys live in the Domain (`AvatarFrame`); an invalid frame can't be persisted.
- Animated frames are pure CSS/SVG and reduced-motion aware (degrade to static), keeping the component a pure
  render function.

### Scope boundaries / deviations
- The animated set went through significant exploration that did **not** ship: literal "rising flames", a
  particle/ember system, an SVG `feTurbulence` fire, and a Lottie pipeline (`lottie-react`) were each tried
  and removed. The final catalog is the clean CSS/SVG set above; `lottie-react` was dropped from
  `package.json`, and `fire-svg.tsx` / `lottie-frame.tsx` / `use-reduced-motion.ts` / the
  `public/avatar-frames/` placeholder were removed in the trim (`f15e1fa`, `50e415b`) - so the net File List
  below reflects only what shipped.
- No gating: any member can pick any frame (frames are cosmetic, not earned).

### Project Structure Notes
- Added api: `Community/Domain/AvatarFrame`, migration `Version20260618190000`.
- Modified api: `Community/Domain/CommunityProfile`, `Community/Application/{UpdateCommunityProfile,
  CommunityProfileView}`, `tests/Functional/CommunityProfileCustomizationTest`.
- Added frontend: `features/community/{avatar-frame.tsx,avatar-frame.module.css,avatar-frames.ts}`.
- Modified frontend: `features/players/{profile-avatar.tsx,player-profile-api.ts,player-profile-page.tsx}`,
  `features/community/{community-profile-api.ts,community-profile-customization-form.tsx}`.

### References
- Epic §E + stories 30.2/30.3. [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Curated avatar-frame catalog (solid rings, neon glows, animated holographic / gold-shimmer / spectral),
  persisted on the profile, reduced-motion aware.
- Built across `56fff97` → `50e415b`; intermediate flame/particle/SVG-turbulence/Lottie experiments were
  removed in the trim. Code-review findings addressed in `50e415b`.

### Validation Results

- Gates green at merge: php-cs-fixer 0 / phpstan 0 / `app:architecture:ddd` exit 0 / phpunit 0 notices
  (incl. `CommunityProfileCustomizationTest`); typecheck / lint / build / jest clean. `lottie-react` removed
  from dependencies in the trim.

### File List

**Added (api)**
- `api/src/Community/Domain/AvatarFrame.php`
- `api/migrations/Version20260618190000.php`

**Modified (api)**
- `api/src/Community/Domain/CommunityProfile.php`
- `api/src/Community/Application/UpdateCommunityProfile.php`
- `api/src/Community/Application/CommunityProfileView.php`
- `api/tests/Functional/CommunityProfileCustomizationTest.php`

**Added (frontend)**
- `frontend/src/features/community/avatar-frame.tsx`
- `frontend/src/features/community/avatar-frame.module.css`
- `frontend/src/features/community/avatar-frames.ts`

**Modified (frontend)**
- `frontend/src/features/players/profile-avatar.tsx`
- `frontend/src/features/players/player-profile-api.ts`
- `frontend/src/features/players/player-profile-page.tsx`
- `frontend/src/features/community/community-profile-api.ts`
- `frontend/src/features/community/community-profile-customization-form.tsx`
