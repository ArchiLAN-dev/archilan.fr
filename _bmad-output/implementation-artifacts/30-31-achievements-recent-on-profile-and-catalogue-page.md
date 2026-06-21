# Story 30.31: Achievements — Recent on Profile + Full Catalogue Page

## Story

**As a** community member,
**I want** my profile to highlight only my most recently earned achievements (with the count of what's
left and a link to a dedicated page listing every achievement of the site, with how rare each one is),
**So that** the profile stays readable as the catalogue grows while still motivating me to earn more.

## Context

The achievements engine (epic 30: stories 30.4, 30.16, 30.26) grants `AchievementGrant`s evaluated from a
fact-based rule tree. Today `CommunityProfileView::forSlug` returns **every** active achievement and the
frontend `ProfileAchievements` (`frontend/src/features/community/profile-achievements.tsx`) renders the
whole grid (unlocked first, then locked). There is **no** public catalogue page (only admin authoring via
`AdminAchievementController`). As the catalogue grows (see story 30.32 adding event achievements), the
profile grid becomes unwieldy and the full list is shipped on every profile load.

This story is **frontend + read-model only** — no rule-engine change. (Scope split from the original
combined story; event-participation rules are story 30.32.)

## Status

done

## Acceptance Criteria

**AC1:** The public profile (`/joueurs/{slug}`) achievement section shows only the **most recently
unlocked** achievements (default 6, by `unlockedAt` desc). Below them: the count **`X / Y succès`** and,
when locked ones remain, a motivational hint **« +N à débloquer »**, plus a link **« Voir tous les
succès »** to the catalogue page. Locked achievements are no longer rendered individually on the profile
card.

**AC2:** A dedicated page **`/joueurs/{slug}/succes`** lists **all** site achievements with the player's
unlocked/locked state (unlocked first, sorted by date; then locked), reusing the existing card visuals
(trophy/lock, kudos where applicable). It shows the player identity header (pseudo + avatar) and a back
link to the profile. Empty state (no unlocked yet) and the monotonic-visibility rule (a deactivated
definition is shown only if this player earned it) are handled by reusing `achievementsFor`.

**AC3:** Each achievement on the catalogue page shows its **rarity** — « X % des joueurs l'ont » (or an
absolute « N joueurs ») — computed from the global grant count over the listable-member base (reuse
`AchievementGrantRepositoryInterface::countByUsers` style aggregation; one batch query, not per-card).

**AC4 (payload split):** `CommunityProfileView::forSlug` stops shipping the full list: it returns only the
recent unlocked slice + `{ unlocked: int, total: int }`. A new read endpoint
`GET /api/v1/community/profiles/{slug}/achievements` returns the full catalogue-with-state (+ rarity) for
the dedicated page. Kudos visibility (no kudos on the owner's own view) is preserved on **both** surfaces.

**AC5:** All quality gates pass (phpstan, php-cs-fixer, phpunit, app:architecture:ddd; frontend
typecheck/lint/build/jest).

## Tasks / Subtasks

- [x] Task 1: API — `AchievementRarityQueryInterface` + `DbalAchievementRarityQuery`: one snapshot of
  distinct holders per key (over listable members) + the member-base size, in one query pair.
- [x] Task 2: API — `CommunityProfileView`: `forSlug` now returns the **recent unlocked slice** (limit 6,
  by unlock date) + `achievementStats {unlocked,total}`; new `achievementsCatalogFor(slug, viewerId)`
  returns the full list-with-state + rarity. Route `GET /community/profiles/{slug}/achievements` on
  `CommunityProfileController`.
- [x] Task 3: API tests — `CommunityProfileAchievementsTest`: `forSlug` returns ≤6 recent unlocked
  (ordered) + counts; catalogue returns the full list with rarity (count + percent) and 404 on unknown
  slug. Existing CommunityProfile suite (30) unaffected.
- [x] Task 4: Frontend — `ProfileAchievements` → recent-only via shared `AchievementCard` + `X/Y` +
  « +N succès à débloquer » + « Voir tous les succès » link. `player-profile-api.ts` gains
  `achievementStats` + parse.
- [x] Task 5: Frontend — route `/joueurs/[playerSlug]/succes` + `AchievementsCataloguePage` (identity
  header, full grid, rarity badge, back link); `getPlayerAchievements` api client (null on bad payload /
  network error).
- [x] Task 6: Frontend tests (jest) — `achievementStats` parse + default, catalogue fetch success / null
  percent / bad payload / network error.
- [x] Task 7: Quality gates — phpstan, php-cs-fixer, phpunit (CommunityProfile 30 + new 3), DDD;
  frontend typecheck/lint/build/jest (143).

## Dev Notes

### Reuse, don't reinvent

The full-list-with-state + kudos gating already exists in `CommunityProfileView::achievementsFor`. The
catalogue endpoint reuses it; the profile slices its result to the recent unlocked. Don't duplicate the
visibility/monotonic logic.

### Rarity is one batch query

Grant counts per achievement key over the listable-member base, divided by member count. Compute once for
the whole page (not per card). Round to a sensible precision; show « Récent » or hide the % when the base
is tiny (avoid "100%" noise on a 1-member dataset).

### Why split the payload

Returning the full catalogue on every profile view scales poorly as the catalogue grows (story premise).
The profile only needs the recent slice + counts; the full list lives behind its own endpoint/page.

### Out of scope

- Sorting/filtering controls on the catalogue page (by date/rarity/category) — future.
- Progress bars toward locked achievements (the engine exposes no partial progress today).