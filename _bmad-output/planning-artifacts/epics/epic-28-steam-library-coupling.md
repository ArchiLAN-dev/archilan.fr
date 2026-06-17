# Epic 28 - Steam Library Coupling

Status: planned (not started)
Date: 2026-06-15

## Goal

Let a visitor on the public **`/jeux`** page discover which games **they already own on Steam** are
playable at ArchiLAN events, by **coupling** their Steam library against the curated ArchiLAN game
catalog (which only contains Archipelago-supported games). The coupling is the **intersection**:
*user's owned Steam games* ∩ *ArchiLAN catalog*.

- The user provides their Steam account **manually** (profile URL or SteamID64). No Steam OpenID /
  account linking in this epic.
- Matching is **exact, by Steam appid** (no fuzzy name matching), enabled by resolving each catalog
  game's Steam appid from IGDB.
- An **authenticated member** can **save** their SteamID on their account for automatic re-coupling on
  return visits. We store the **link (SteamID), not a snapshot** of the owned list - the intersection
  is recomputed on demand.
- An **anonymous visitor** uses it ephemerally (SteamID kept client-side in `localStorage`).

## Decisions (locked)

- **Source:** Steam, **manual entry** (profile URL or SteamID64). OpenID/account-linking explicitly
  dropped (does not grant access to private libraries anyway, so it adds complexity without removing
  the public-library constraint).
- **Matching:** exact by **Steam appid**. Each catalog `Game` is enriched with a nullable
  `steamAppId`, resolved from **IGDB `external_games`** during the existing `GameCatalogSync`.
- **Persistence:** store the **SteamID only** on the member account (no owned-list snapshot); recompute
  the intersection on demand. Consistent with the project's "no snapshot, resolve on demand" principle.
- **No "games you don't own yet" suggestions** in this MVP - the result is the intersection only.
  (Discovery / wishlist suggestions deferred to a later iteration.)
- **Privacy:** reading the owned list requires the user's Steam **"Game details"** privacy to be
  **public** - true regardless of entry method. A private library yields a clear, actionable message,
  not an error. Storing a member's SteamID is personal data → cover it in the existing privacy page.

## Scope

### In scope
- `steamAppId` enrichment on catalog games via IGDB `external_games`.
- Steam Web API integration (`GetOwnedGames`, vanity-URL resolution via `ResolveVanityURL`).
- Public coupling endpoint computing the intersection.
- Saving / clearing a member's SteamID on their account; auto-coupling for logged-in members.
- `/jeux` UI: manual Steam input, result summary banner, "you own this" badge on matched cards,
  private-library guidance.

### Out of scope
- Steam OpenID / "Sign in through Steam" account linking.
- Suggestions of compatible games the user does **not** own.
- Non-Steam platforms (GOG, itch, console, physical). Games without a Steam appid simply won't match
  via Steam; a future manual-selection mode could complement this (open door, not built here).
- Caching strategy beyond a short server-side cache of the owned list to respect Steam rate limits.

## Affected systems (verified)

- **api/ `GameSelection`** - `Game` domain + `GameCatalogSync` already hold `igdbId`; IGDB client
  already exists (`IgdbHttpClientInterface` / `IgdbHttpClient` / `StubIgdbHttpClient`,
  `AdminIgdbController`). Add `steamAppId` resolution (IGDB `external_games`) + persistence + a new
  Steam Web API client + a `SteamLibraryCouplingQuery` read model + a public endpoint.
- **api/ Accounts/Auth context** - add a nullable `steamId` field to the member account + endpoints to
  set/clear it (story 3 will confirm the exact context/entity).
- **frontend/** - `app/(public)/jeux/page.tsx` (Server Component, renders catalog) + a new client
  coupling component (TanStack mutation), `features/games/public-games-api.ts` (extend `PublicGame`
  with `steamAppId`), a new `features/games/steam-coupling-api.ts` (+ type guard), `GameCard` badge,
  and the `/compte` area for saving the SteamID.
- **Config** - new env `STEAM_WEB_API_KEY` (api/ side; the frontend calls the api/ endpoint, no new
  client env).

## Proposed stories

- **28.1 - Enabler: `steamAppId` on `Game` via IGDB sync (api/).** Add a nullable `steamAppId` to the
  `Game` domain + `GameCatalogSync`, resolved from IGDB `external_games` (Steam category) during the
  existing sync. Migration, repository/DBAL read exposure, expose `steamAppId` on the public `GET /games`
  payload. Backfill existing games on next sync. Unit + functional tests. No UI.
- **28.2 - Steam Web API integration + coupling endpoint (api/).** `SteamWebApiClientInterface`
  (Application) + `SteamWebApiClient` (Infrastructure, calls `ResolveVanityURL` + `GetOwnedGames`) +
  `StubSteamWebApiClient` (tests), mirroring the IGDB client trio. `SteamLibraryCouplingQuery`
  (read-side, no transaction) parses the input (full URL / vanity / SteamID64), resolves to a
  SteamID64, fetches owned appids, intersects with catalog `steamAppId`s, returns matched games +
  counts (owned total, matched total). Public endpoint `POST /games/steam-coupling`. Env
  `STEAM_WEB_API_KEY`. Handle private-library + invalid-input cases explicitly. Functional tests with
  the stub.
- **28.3 - Save SteamID on member account (api/ + frontend).** Nullable `steamId` on the member account,
  set/clear endpoints (member-gated), and a `/compte` control to save / remove it. On a logged-in
  visit to `/jeux`, the saved SteamID pre-fills and auto-couples. Store the SteamID only (no snapshot).
  Tests both layers; privacy-page mention of the stored SteamID.
- **28.4 - Coupling UI on `/jeux` (frontend).** Manual Steam input (URL / SteamID64),
  `features/games/steam-coupling-api.ts` (+ `is*` type guard, returns typed result or `null`), result
  summary banner ("X of your N Steam games are playable at ArchiLAN"), "you own this" badge overlay on
  matched `GameCard`s, anonymous `localStorage` persistence of the SteamID, and clear private-library
  guidance with a link to the Steam privacy setting. Gates: typecheck / lint / build.
- **28.5 - Jeux page redesign (frontend + small api/).** Post-MVP rework from local-test feedback:
  client-driven catalog (full catalog fetched once via `GET /games?all=1`), instant search, filters
  (availability + "owned only"), sort, and the coupling **merged into the main grid** (owned badge +
  owned-only filter across the whole catalog instead of a separate result grid). Reuses 28.1-28.4.
- **28.6 - Platform categories (frontend + api/).** Enrich games with IGDB `platforms`, mapped to a
  **curated family set** (Super Nintendo, GameCube, N64, PC, PlayStation, Switch…), exposed on the
  catalog and surfaced as multi-select category chips on `/jeux`, plus a derived "Steam" store facet
  (from `steamAppId`). Backfill `app:games:backfill-platforms`. Reuses the 28.1/28.5 patterns.
- **28.7 - Categories + Steam coupling on the run game-selection page (frontend + api/).** Bring the
  category chips and Steam coupling to `/runs/{runId}/jeux`: enrich the run selection payload with
  `platforms`/`steamAppId`, extract a shared `SteamCoupling` component + `useSteamCoupling` hook reused
  by both pages, and add the owned label + "Mes jeux" filter to the run catalog.
- **28.8 - "Récemment joués" surfaced on the run game-selection page (frontend + api/).** Compute the
  user's 3 most recently played games from their personal-run history (`run` + `run_participant.game_slots`)
  via a new Application read query (DBAL in Infrastructure), expose them (with `lastPlayedAt` + `runTitle`)
  on the run game-selection payload, **bubble them to the top of the catalog listing** with a
  recency-aware "Récemment joué" badge, add a "Récemment joués" filter chip (consistent with the 28.7
  "Mes jeux"/category chips), and handle the "déjà sélectionné" state. See the detailed spec below.

## Sequencing

Data enabler first, then the engine, then experience:
`28.1` (steamAppId enrichment) → `28.2` (Steam client + coupling endpoint) → `28.4` (public UI) with
`28.3` (member persistence) layered in after `28.2` (can run in parallel with `28.4`).

## Risks / notes

- **IGDB Steam coverage:** not every IGDB game has a Steam `external_games` entry (console/Nintendo
  titles). Those catalog games will simply never match via Steam - acceptable for the MVP; surface the
  limitation in copy if useful.
- **Steam privacy:** `GetOwnedGames` returns nothing if the target's "Game details" is private -
  cannot be bypassed by our API key. Must be a friendly, actionable message, not a hard error.
- **Vanity vs SteamID64:** input can be a full profile URL, a vanity name, or a raw SteamID64 -
  normalize and resolve robustly (`ResolveVanityURL` only for vanity names).
- **Steam rate limits:** add a short server-side cache of the owned list keyed by SteamID; never store
  it as a durable snapshot.
- **RGPD:** storing a member's SteamID is personal data - include it in the deletion/erasure path and
  the privacy page.
- **Foundation already in place:** `igdbId` on every catalog game + a working IGDB HTTP client make
  the appid enrichment (28.1) low-risk and low-surface.

## Story 28.8 - "Récemment joués" surfaced on the run game-selection page

Status: planned (not started)
Date: 2026-06-17

### Story

As a member configuring a personal run on `/runs/{runId}/jeux`,
I want my 3 most recently played games to surface automatically at the top of the catalog,
So that I can re-pick the games I actually play without searching the whole library every time.

### Context & decisions (locked)

- **"Jeux joués" = the user's own run history.** A game counts as *played* when it appears in the
  current user's `run_participant.game_slots` for one of their runs (owned **or** joined) that has been
  **launched at least once** - status in `started`/`active`/`idle`/`restarting`/`completed`. Draft-only
  and cancelled runs are excluded, because the games there were selected but never actually played.
- **Recency = the run's `updated_at` (desc).** Games are de-duplicated by `gameId` keeping the most
  recent occurrence; the top **3 distinct** game IDs are returned.
- **The current run is excluded** from the history scan (we are surfacing *past* plays, not the run being
  edited). Games already in the current selection are not filtered out - they simply also appear in
  "Ma sélection" as today.
- **Presentation = bubble into the listing (preferred), not a separate card row.** When no search term is
  active, the recently-played games are pinned to the **top of the catalog list** in recency order, each
  carrying a small "Récemment joué" badge (mirroring the existing "Tu possèdes ce jeu" badge). A live
  search reverts to the normal flat, name-ordered list (the pin would fight the user's query).
- **A "Récemment joués" filter chip** sits alongside the existing "Mes jeux" (Steam-owned) and category
  chips - same toggle pattern from 28.7. Toggling it restricts the catalog to the recently-played set and
  **combines** with the other active filters (categories, "Mes jeux", availability), exactly like they
  combine with each other. This is the accessible, discoverable surface (a real announced control rather
  than a silent DOM reorder), and it resolves the pin-vs-filter interaction: pinning is the *default-view*
  affordance, the chip is the *explicit* one.
- **Recency context in the badge.** The badge is not binary: it carries a relative-time + source hint
  ("Joué il y a 3 j", with the run title in the `title`/tooltip, e.g. *"dans Ma run du vendredi"*). This
  gives information scent and justifies the ordering. The payload therefore returns, per game, the
  `gameId`, the `lastPlayedAt` timestamp, and the most recent `runTitle` - not a bare id list.
- **"Déjà dans ta sélection" state.** A recently-played game that is *already* in the current working
  selection is not pinned a second time and shows no "+ Ajouter" duplication - it is either omitted from
  the pinned strip (it already appears under "Ma sélection") or, if still listed, its row reflects the
  selected state. No game is actionable twice for the same outcome.
- **Empty history is silent** - no badge, no pinning, no chip, no placeholder. Brand-new members see the
  catalog exactly as today (the chip only renders when the set is non-empty, like "Mes jeux" only renders
  when `coupled`).
- **No new persistence.** The list is recomputed on demand from existing tables (consistent with the
  epic's "resolve on demand, no snapshot" principle). No migration.

### Acceptance Criteria

1. Given a member who has played at least one launched personal run, when they open `/runs/{runId}/jeux`
   with no active search, then the games from their 3 most recent distinct plays appear first in the
   catalog, each with a "Récemment joué" badge.
2. The badge carries recency context: a relative-time label ("Joué il y a 3 j") with the source run title
   available on hover/`title`.
3. A "Récemment joués" filter chip renders (only when the set is non-empty) next to the existing
   "Mes jeux"/category chips; toggling it restricts the catalog to recently-played games and combines with
   any other active filter and the search term.
4. De-duplication is by game; the same game played in several runs appears once, positioned and dated by
   its most recent play.
5. Draft-only runs, cancelled runs, and the run currently being edited never contribute to the list.
6. A recently-played game already present in the current working selection is not offered as a duplicate
   action (omitted from the pinned strip or shown in its selected state) - never actionable twice for the
   same result.
7. A member with no qualifying run history sees the catalog unchanged (no badges, no pinning, no chip, no
   errors).
8. Typing in the catalog search disables the pinning and shows the normal name-ordered, filtered results;
   the "Récemment joué" badge still renders on any matching recently-played game.
9. The recently-played computation is scoped to the **authenticated user only** - it never leaks another
   member's history (verify a joined-but-not-owner participant only sees their own plays).

### Tasks / Subtasks

- [ ] **api/ Application:** define `RecentlyPlayedGamesQueryInterface` in `PersonalRuns/Application` with
      `recentlyPlayed(string $userId, string $excludeRunId, int $limit = 3): list<array{gameId: string,
      lastPlayedAt: string, runTitle: string}>` (read-only, no transaction).
- [ ] **api/ Infrastructure:** `DbalRecentlyPlayedGamesQuery` implementing it with a DBAL QueryBuilder over
      `run_participant` joined to `run`, filtered on `user_id`, launched statuses, and `run.id != :current`,
      ordered by `run.updated_at DESC`; iterate `game_slots` JSON in PHP, dedupe by `gameId` keeping the
      most recent (carry its `run.updated_at` + `run.title`), cap at the limit. Register in `services.yaml`
      (no `when@test` gating - real impl).
- [ ] **api/ Application:** inject the query into `PersonalRunGameSelection`; extend `getMySlots()` to add
      `recentlyPlayedGames: list<array{gameId,lastPlayedAt,runTitle}>` to its result and the `result()`
      shape/docblocks.
- [ ] **api/ Presentation:** add `recentlyPlayedGames` to the `GET .../participants/me/game-selection`
      payload in `PersonalRunController::getMyGameSelection`.
- [ ] **api/ tests:** unit-test the query interface contract via a stub; functional test on the endpoint
      covering AC 1, 4, 5, 7 and AC 9 (own-history isolation). Honour the zero-notice gate.
- [ ] **frontend:** extend the `SelectionData`/payload type with
      `recentlyPlayedGames: { gameId: string; lastPlayedAt: string; runTitle: string }[]`; derive a
      recency-ordered "pinned" slice (excluding games already in `workingGameIds`) and render it above the
      remaining catalog when `gameSearch` is empty; add the "Récemment joué" badge (relative time +
      `title` run name) alongside the existing availability/owned badges in the catalog row.
- [ ] **frontend:** add the "Récemment joués" toggle chip next to "Mes jeux"/categories (render only when
      the set is non-empty), filtering `filteredGames` and resetting the page like the other chips; ensure
      it combines with search/availability/category/owned filters.
- [ ] **frontend:** keep pagination correct with the pinned section (pin within page 1 / the active filter
      result, do not double-list a pinned game lower down) and reflect the "déjà sélectionné" state.
- [ ] Gates: `phpstan` / `php-cs-fixer` / `phpunit` (0 notices) / `app:architecture:ddd` ; `typecheck` /
      `lint` / `build`.

### Affected systems (verified)

- **api/ `PersonalRuns`** - `PersonalRunGameSelection::getMySlots()`
  (`api/src/PersonalRuns/Application/PersonalRunGameSelection.php`) and
  `PersonalRunController::getMyGameSelection()` (`...:224`). New query interface + DBAL impl. The
  `run_participant.game_slots` JSON column and `run.updated_at`/`status` already hold everything needed -
  no schema change.
- **frontend/** - `features/personal-runs/personal-run-game-selection-page.tsx` (the catalog list build at
  the `filteredGames` / `pageGames` section, ~l.247-263, and the badge block ~l.464-477).

### Notes / risks

- **DDD:** the read must use a DBAL QueryBuilder in Infrastructure behind an Application query interface -
  not `EntityManager`/`Connection` in `PersonalRunGameSelection` (AC-A2). JSON `game_slots` is iterated in
  PHP after the fetch (no raw JSON SQL), keeping it portable and PHPStan-clean.
- **"Joué" vs "sélectionné":** gating on launched statuses is the deliberate interpretation of *played*;
  if product later wants "selected" semantics, widen the status set in one place (the query).
- **Reuse:** the badge follows the existing "Tu possèdes ce jeu" pattern and the chip follows the 28.7
  "Mes jeux"/category chip pattern; no new design tokens. Consider a future shared `useRecentlyPlayed`
  hook only if the public `/jeux` page later wants the same surface (out of scope here).
- **Cross-ref [[story-16-11]]:** once user YAML templates exist (Story 16.11, Personal Runs epic), the
  recently-played surface is the natural place to also offer "reprendre ce jeu avec ma dernière config".
  Kept out of 28.8 deliberately - 28.8 ships the discovery surface, 16.11 ships the config-reuse engine.

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-15 | Epic planned. Source = Steam manual entry; exact match by appid (IGDB external_games); member SteamID persistence (no snapshot); no "not-owned" suggestions in MVP. Stories 28.1-28.4 proposed. |
| 2026-06-17 | Story 28.8 added: surface the user's 3 most recently played games (from `run` history) bubbled to the top of the run game-selection catalog with a "Récemment joué" badge. |
| 2026-06-17 | Story 28.8 refined (UX): added "Récemment joués" filter chip, recency-aware badge (`lastPlayedAt` + `runTitle`), and "déjà sélectionné" handling; payload now returns game metadata, not a bare id list. |
| 2026-06-17 | Named YAML templates story (briefly drafted here as 28.9) moved to Epic 16 as Story 16.11 - it belongs to the Personal Runs context, not Steam coupling. 28.8 cross-references it. |
