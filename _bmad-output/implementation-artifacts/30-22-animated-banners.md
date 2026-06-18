# Story 30.22: Animated layered profile banners

Status: done (retroactively documented)

## Story

As a member,
I want richer animated banner presets for my profile,
so that my profile header feels alive instead of a flat colour band. Deps: 30.3 (banner preset on profile),
30.19 (shared banner-preset metadata).

A layered animated banner (panning gradient + drifting mesh blobs + subtle texture) with an expanded preset
catalog, honouring reduced-motion, with the header content kept legible above it.

## Acceptance Criteria

1. `BannerPreset` (Domain) offers the expanded catalog (default, sunset, forest, arcade, midnight, aurora,
   ocean, neon, retrowave, pastel); each renders as a layered animated banner.
2. The banner is a dedicated component (`profile-banner.tsx` + CSS module) composing a panning gradient, mesh
   blobs and a texture layer.
3. Profile header content stays above the banner and readable (contrast handled; the "Profil joueur" eyebrow
   removed for cleanliness).
4. Animation respects `prefers-reduced-motion` (no motion when the user opts out).
5. Gates green: phpstan / php-cs-fixer / `app:architecture:ddd`; typecheck / lint / build / jest.

## Tasks / Subtasks

- [x] **api/ Domain:** expand `BannerPreset` valid keys.
- [x] **frontend:** `profile-banner.tsx` + `profile-banner.module.css` (gradient + mesh + texture layers);
      extend `banner-presets.ts`; wire into the editor + `player-profile-page.tsx`.
- [x] **frontend:** keep header content above the banner + fix text contrast; drop the eyebrow
      (`23e578b`, `adb3718`).
- [x] **frontend:** `community-profile-api.ts` typing for the expanded presets.
- [x] **Gates** — all green.

## Dev Notes

### Reuse, don't reinvent
- Presets are the same `banner_preset` field from 30.3 with more allowed values — no schema change. The
  shared `banner-presets.ts` (30.19) is the single preset source for editor + profile.

### Architecture guardrails
- Allowed preset keys are validated in the Domain (`BannerPreset`); an unknown preset can't be stored.
- The banner is a pure CSS-animated component; reduced-motion is honoured so it degrades to a static banner.

### Scope boundaries / deviations
- Preset catalog only — no fully custom/uploaded banners.
- Avatar frames are a separate concern (30.23); the header layout that lets the avatar straddle the banner is
  finalised in 30.24.

### Project Structure Notes
- Modified api: `Community/Domain/BannerPreset`.
- Added frontend: `features/community/{profile-banner.tsx,profile-banner.module.css}`.
- Modified frontend: `features/community/{banner-presets.ts,community-profile-api.ts,
  community-profile-customization-form.tsx}`, `features/players/player-profile-page.tsx`.

### References
- Epic §E + stories 30.3/30.19. [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Layered animated banners (gradient + mesh + texture) with an expanded preset catalog, reduced-motion
  aware, header content kept legible above.
- Implemented in commits `b7817b5` (banners), `23e578b` (content above banner) and `adb3718` (contrast +
  drop eyebrow).

### Validation Results

- Gates green at merge: php-cs-fixer 0 / phpstan 0 / `app:architecture:ddd` exit 0 / phpunit 0 notices;
  typecheck / lint / build / jest clean.

### File List

**Modified (api)**
- `api/src/Community/Domain/BannerPreset.php`

**Added (frontend)**
- `frontend/src/features/community/profile-banner.tsx`
- `frontend/src/features/community/profile-banner.module.css`

**Modified (frontend)**
- `frontend/src/features/community/banner-presets.ts`
- `frontend/src/features/community/community-profile-api.ts`
- `frontend/src/features/community/community-profile-customization-form.tsx`
- `frontend/src/features/players/player-profile-page.tsx`
