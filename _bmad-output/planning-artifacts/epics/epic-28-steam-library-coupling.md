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

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-15 | Epic planned. Source = Steam manual entry; exact match by appid (IGDB external_games); member SteamID persistence (no snapshot); no "not-owned" suggestions in MVP. Stories 28.1-28.4 proposed. |
