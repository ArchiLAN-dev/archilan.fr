# Story 30.6: Showcases

Status: ready-for-review

## Story

As a member,
I want to arrange showcase widgets on my profile (favorite games, featured achievements, best runs,
most-played),
so that I can highlight what I'm proud of. Deps: 30.3, 30.4.

## Acceptance Criteria

1. `CommunityProfile` stores an ordered `showcaseLayout` (subset of the `ShowcaseWidget` catalog,
   deduped); part of the audience-gated customization.
2. The owner edits the showcase via the `/compte` "Profil" tab (enable widgets, reorder, remove); invalid
   widget keys are dropped, duplicates collapsed.
3. The profile renders a "Vitrine" section showing the chosen widgets in the owner's order, composed from
   data already on the page (favorite games, unlocked achievements, run history); empty widgets are skipped.
4. Gates green: phpstan / php-cs-fixer / phpunit (0 notices) / `app:architecture:ddd`; typecheck / lint /
   build / jest.

## Tasks / Subtasks

- [x] **api/ Domain:** `ShowcaseWidget` (valid keys: favorite_games / featured_achievements / best_runs /
      most_played); `CommunityProfile` += `showcaseLayout` + `customize()` param + getter.
- [x] **api/ Migration:** `showcase_layout` JSON column.
- [x] **api/ Application:** `UpdateCommunityProfile` parses/validates the layout (subset, dedupe);
      `CommunityProfileView` exposes `showcaseLayout` in the customization payload + editable read.
- [x] **api/ tests:** functional (`testShowcaseLayoutIsSavedDedupedAndFiltered`).
- [x] **frontend:** showcase manager (enable/reorder/remove) in the customization form; `ProfileShowcase`
      on the profile page renders widgets from existing data (favorites, achievements, best runs by checks,
      most-played by count); types/guards extended.
- [x] **Gates** - all green.

## Dev Notes

### Reuse, don't reinvent
- `best_runs` and `most_played` are derived **client-side** from the run history already fetched by the
  profile page (`getPlayerHistory`) - no new backend read. `favorite_games`/`featured_achievements` reuse
  the profile payload. So 30.6 is essentially the `showcaseLayout` field + rendering.

### Architecture guardrails
- Layout is validated against the `ShowcaseWidget` catalog in Application (invalid keys dropped silently,
  not a 422 - they're a client/version mismatch, not user error). Gated by the profile audience like the
  rest of customization.

### Scope boundaries / deviations
- `currently_playing` widget deferred to 30.14 (presence) - not in the catalog yet.
- The Vitrine is an additive "pinned highlights" section above the full sections (bio/favorites/links,
  achievements, history); some overlap is the owner's choice. A later pass could let it replace the
  default ordering.

### Project Structure Notes
- New api: `Community/Domain/ShowcaseWidget`, migration. Modified: `CommunityProfile`,
  `UpdateCommunityProfile`, `CommunityProfileView`, `CommunityProfileCustomizationTest`.
- Frontend: `player-profile-api.ts` + `community-profile-api.ts` (showcase types/guards/consts),
  `community-profile-customization-form.tsx` (manager), `player-profile-page.tsx` (`ProfileShowcase`).

### References
- Epic story 30.6 (Track 2). [Source: _bmad-output/planning-artifacts/epics/epic-30-community-enriched-profiles.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

- Owner-arranged showcase: ordered widget keys on the profile (gated customization), edited via a
  reorder/add/remove manager; rendered as a "Vitrine" composed from existing profile + history data.
- No new backend read - best-runs/most-played derive from the history already on the page.
- Deviation: `currently_playing` deferred to 30.14; Vitrine is additive (pinned) above the full sections.

### Validation Results

- php-cs-fixer 0 ; phpstan 0 ; app:architecture:ddd exit 0 ; phpunit 1148 tests, 0 notices.
- pnpm typecheck / lint / build / test (jest 86): clean.

### File List

**Added (api)**
- `api/src/Community/Domain/ShowcaseWidget.php`
- `api/migrations/Version20260618110000.php`

**Modified (api)**
- `api/src/Community/Domain/CommunityProfile.php` (showcaseLayout field + customize param + getter)
- `api/src/Community/Application/UpdateCommunityProfile.php` (parse/validate layout)
- `api/src/Community/Application/CommunityProfileView.php` (showcaseLayout in payloads)
- `api/tests/Functional/CommunityProfileCustomizationTest.php` (showcase test)

**Modified (frontend)**
- `frontend/src/features/players/player-profile-api.ts` (showcaseLayout in customization type/guard)
- `frontend/src/features/players/player-profile-page.tsx` (ProfileShowcase)
- `frontend/src/features/community/community-profile-api.ts` (showcase types/guard/consts)
- `frontend/src/features/community/community-profile-customization-form.tsx` (showcase manager)
