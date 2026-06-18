# Story 30.18: Display-name override + favorites consolidation

Status: done (retroactively documented)

## Story

As a member,
I want an optional display name distinct from my account username, and my favourite games managed in one
place (the Vitrine) rather than in two,
so that my profile shows the name I choose and favourites aren't configured/rendered twice. Deps: 30.3
(profile customization), 30.6 (showcases / Vitrine).

An optional `display_name` override (falls back to the username) plus the removal of the duplicate
favourites surface — favourites live only in the Vitrine showcase.

## Acceptance Criteria

1. `CommunityProfile` gains an optional `display_name` (VARCHAR 80, nullable). `customize()` accepts it; the
   effective profile name is `displayName ?? username`.
2. The customization form lets a member set/clear the display name; the public profile and reads use the
   effective name everywhere the username appeared.
3. Favourite games are managed **only** through the Vitrine (showcase) — the separate favourites editor is
   gone, and the public profile no longer renders favourite games twice.
4. Migration `Version20260618180000` adds `display_name` and is reversible (`down()` drops it).
5. Gates green: phpstan / php-cs-fixer / phpunit (0 notices) / `app:architecture:ddd`; typecheck / lint /
   build / jest.

## Tasks / Subtasks

- [x] **api/ Domain:** `CommunityProfile` — `display_name` column + `customize()` signature + getter.
- [x] **api/ Application:** `UpdateCommunityProfile` persists the display name; `CommunityProfileView`
      resolves the effective name (`displayName ?? model.username`).
- [x] **api/ Presentation:** `CommunityProfileController` accepts the new field.
- [x] **api/ Migration:** `Version20260618180000` (`display_name`).
- [x] **api/ tests:** `CommunityProfileCustomizationTest` covers set / fallback.
- [x] **frontend:** `community-profile-api.ts` + `community-profile-customization-form.tsx` (display-name
      field); `player-profile-page.tsx` uses the effective name + stops the double favourites render.
- [x] **Gates** — all green.

## Dev Notes

### Reuse, don't reinvent
- Favourites are not a second feature — they are the Vitrine's existing favourites widget (30.6). This story
  *removes* the redundant favourites editor/render rather than adding anything.

### Architecture guardrails
- `display_name` is part of the customization aggregate; mutated only through `customize()` (no setter,
  AC-D5).
- Effective-name resolution lives in the Application read, so every consumer sees one name.

### Scope boundaries / deviations
- The display name is cosmetic — the slug/username is unchanged (URLs and identity are stable).
- No uniqueness constraint on display names (it's a label, not an identifier).

### Project Structure Notes
- Modified api: `Community/Domain/CommunityProfile`, `Community/Application/{UpdateCommunityProfile,
  CommunityProfileView}`, `Community/Presentation/CommunityProfileController`, migration
  `Version20260618180000`, `tests/Functional/CommunityProfileCustomizationTest`.
- Modified frontend: `features/community/{community-profile-api.ts,community-profile-customization-form.tsx}`,
  `features/players/player-profile-page.tsx`.

### References
- Epic §A/§E + stories 30.3/30.6. [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Optional display-name override with username fallback; favourites consolidated into the Vitrine and the
  public profile no longer renders them twice.
- Implemented in commits `da234da` (override + merge) and `a6ac80d` (stop double favourites render).

### Validation Results

- Gates green at merge: php-cs-fixer 0 / phpstan 0 / `app:architecture:ddd` exit 0 / phpunit 0 notices
  (incl. `CommunityProfileCustomizationTest`); typecheck / lint / build / jest clean.

### File List

**Added (api)**
- `api/migrations/Version20260618180000.php`

**Modified (api)**
- `api/src/Community/Domain/CommunityProfile.php`
- `api/src/Community/Application/UpdateCommunityProfile.php`
- `api/src/Community/Application/CommunityProfileView.php`
- `api/src/Community/Presentation/CommunityProfileController.php`
- `api/tests/Functional/CommunityProfileCustomizationTest.php`

**Modified (frontend)**
- `frontend/src/features/community/community-profile-api.ts`
- `frontend/src/features/community/community-profile-customization-form.tsx`
- `frontend/src/features/players/player-profile-page.tsx`
