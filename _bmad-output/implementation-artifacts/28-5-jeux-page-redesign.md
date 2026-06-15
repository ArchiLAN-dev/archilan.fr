# Story 28.5: Jeux page redesign — client-driven catalog with coupling, filters, sort & instant search

Status: ready-for-review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a visitor on `/jeux`,
I want one fast, unified catalog where I can search instantly, filter and sort, and see at a glance which games I own (Steam coupling) — without page reloads,
so that browsing and "what can I play at ArchiLAN" feel like a single fluid experience.

This **reworks the whole `/jeux` page** on top of the Epic 28 coupling MVP (28.1–28.4). The separate coupling result grid is merged into the main catalog: coupling now **badges and filters the single catalog grid**. The catalog becomes **client-driven** (the full catalog — ~592 games — is fetched once server-side, then search/filter/sort/badge happen instantly in the browser).

## Acceptance Criteria

1. The page server component fetches the **full catalog once** (all games, not paginated) and passes it to a client catalog component. Initial HTML is server-rendered (SEO/first paint preserved); all subsequent interactions are client-side with no navigation/reload.
2. **Instant search**: typing filters the grid live (debounced, client-side) over name + description. No GET form reload; the URL `?q=` is no longer required (may be dropped or kept as a shareable initial value — see Dev Notes).
3. **Filters**: availability (`available` / `experimental`) and an **"Mes jeux" (owned only)** toggle. The owned toggle is enabled only after a successful Steam coupling.
4. **Sort**: at least name A→Z / Z→A; owned-first when a coupling is active.
5. **Coupling merged into the grid**: the Steam input stays at the top; on success, every catalog card the user owns shows the **"Tu possèdes ce jeu"** badge (across the whole catalog, not a separate grid), the summary banner shows **"X de tes N jeux Steam sont jouables"**, and the "Mes jeux" filter becomes usable. Private-profile / invalid / error states keep the 28.4 messaging.
6. Anonymous `localStorage` prefill and logged-in `user.steamProfile` prefill + "Enregistrer sur mon compte" (28.3) are preserved.
7. Empty states: no search match, no games owned-matched, catalog empty — all handled with clear copy. Keyboard/a11y: search and toggles are reachable and labelled.
8. Gates green: `pnpm typecheck`, `pnpm lint`, `pnpm build`, `pnpm test` (jest). Backend gates green for the catalog-all change.

## Tasks / Subtasks

- [ ] **Backend: return the full catalog** (AC: 1)
  - [ ] Extend the public catalog read to support a non-paginated "all" mode. Add `GameCatalogQueryInterface::all(string $query = ''): list<array{...}>` (same item shape as `list`, incl. `steamAppId`) + implement in `DbalGameCatalogQuery` (reuse `buildBaseQuery`, no `PaginationHelper`). Expose via `PublicGameCatalogController` on `GET /api/v1/games?all=1` (when `all=1`, return `{ data: [...] }` with the full list; keep the paginated branch unchanged for back-compat). Functional test: `?all=1` returns every available/experimental game with `steamAppId`.
  - [ ] Note: ~592 rows, small payload — no perf concern. Keep `availability IN (available, experimental)` filter.
- [ ] **Frontend API: fetch-all** (AC: 1)
  - [ ] In `features/games/public-games-api.ts`, add `getAllPublicGames(): Promise<PublicGame[]>` hitting `?all=1`, reusing `isPublicGame` (returns `[]` on failure). Keep `getPublicGames` for any other caller.
- [ ] **Frontend: client catalog component** (AC: 2–5, 7)
  - [ ] New `features/games/games-catalog.tsx` (`"use client"`), props `{ initialGames: PublicGame[] }`. Holds state: `query` (debounced), `availabilityFilter`, `ownedOnly`, `sort`, and coupling state (`matchedAppIds: Set<number>`, `ownedCount`, `couplingOutcome`). Derives the visible list with `useMemo` over `initialGames` (no impure calls in render; debounce via a small `useDebouncedValue` hook or `setTimeout` in an effect/handler).
  - [ ] Render: Steam coupling input + summary/messages (reuse logic from `steam-coupling-panel.tsx` — extract the coupling form/messages into a small shared piece or fold into this component), controls row (search, availability filter, owned toggle, sort), then the grid of `GameCard` with `owned={matchedAppIds.has(game.steamAppId ?? -1)}`.
  - [ ] Owned set comes from `coupleSteamLibrary(...)` (28.4 api): map `matchedGames[].steamAppId` into the set; badge across the full `initialGames` by `steamAppId` membership.
- [ ] **Frontend: page wiring** (AC: 1)
  - [ ] `app/(public)/jeux/page.tsx`: keep it a Server Component; fetch `getAllPublicGames()` and render `<GamesCatalog initialGames={...} />`. Remove the server-side pagination UI and the GET search form (now client-side). Keep the header section and `GameRequestSection`. Decide on `?q=` (drop, or pass as `initialQuery`).
  - [ ] Remove/replace the now-obsolete `SteamCouplingPanel` usage (its logic moves into `GamesCatalog`; delete the file if nothing else uses it, or keep it as the coupling sub-piece).
- [ ] **Tests** (AC: 8)
  - [ ] Jest: extend `public-games-api.test.ts` (or new) for `getAllPublicGames` (ok / network error / bad shape). If a render test harness exists, a light `games-catalog` interaction test (search filters, owned toggle gating) — otherwise keep logic in pure helpers and unit-test those (e.g., a `filterAndSortGames(games, criteria)` pure function).
  - [ ] Backend functional test for `?all=1`.

## Dev Notes

### Dependencies & reuse
- Builds on 28.1 (`steamAppId` on payload), 28.2 (`POST /games/steam-coupling`), 28.3 (account prefill/save), 28.4 (`coupleSteamLibrary`, `GameCard owned` prop, panel logic). Reuse all of these — do not duplicate the coupling fetch or the badge.
- `GameCard` already accepts `owned?: boolean` (28.4). [Source: frontend/src/features/games/game-card.tsx]
- Coupling api + types already exist: `coupleSteamLibrary`, `CouplingResult`, `CoupledGame`. [Source: frontend/src/features/games/steam-coupling-api.ts]
- Auth prefill/save: `useAuth().user?.steamProfile`, `saveSteamAccount`. [Source: frontend/src/features/auth/auth-context.tsx, frontend/src/features/auth/steam-account-api.ts]

### Architecture decision (locked)
- **Client-driven catalog**: the full catalog is fetched once (server component → client component prop) and all search/filter/sort/badge run in the browser. Rationale: ~592 games is a small dataset; this satisfies instant search + owned-badge across the whole grid + owned-only filter + sort without per-page server round-trips. [Decision confirmed with product owner.]
- Keep the page a Server Component shell for SSR/SEO of the initial list; interactivity lives in one client island (`GamesCatalog`). This respects AC-NX1/AC-NX4 (no `useEffect` for the *initial* fetch — it's server-side; client only handles interactions).

### Frontend standards (frontend/AGENTS.md)
- No `process.env` (use `env.ts`); no `as` at API boundary (type guards); `unknown` → validated. No impure calls during render — debounce/`localStorage` only in handlers/effects (AC-HK3). Stable list keys (`game.id`). Tailwind tokens only. Mobile-first controls row that wraps on small screens.
- The coupling action is a button/submit handler (not fetch-in-`useEffect`), consistent with the existing `GameRequestSection` pattern; no TanStack/QueryClientProvider is set up in this app, so keep `useState` + async handlers.

### UX notes
- Controls row: search input (grows), availability segmented control or select, "Mes jeux" toggle (disabled with tooltip "Couple ta bibliothèque Steam d'abord" until a coupling succeeds), sort select.
- Result count line: "X jeux" / "X jeux possédés sur N" when coupled.
- Keep 28.4 outcome copy for private/invalid/steam_error verbatim.
- Owned-first sort when coupling active makes the value obvious immediately.

### Scope boundaries
- No "games you don't own" suggestions (still deferred per epic).
- No server-side filtering/sort params (all client-side) — the `?all=1` endpoint is the only backend change.
- Virtualization not required at ~592 items; if scroll perf is poor, add `content-visibility: auto` on cards before reaching for a virtualization lib.

### Project Structure Notes
- New (frontend): `features/games/games-catalog.tsx` (+ optional `use-debounced-value.ts` / `filter-sort` pure helper + its test).
- Modified (frontend): `app/(public)/jeux/page.tsx`, `features/games/public-games-api.ts` (+ test). `steam-coupling-panel.tsx` folded into `GamesCatalog` (delete or repurpose).
- New/Modified (api): `GameCatalogQueryInterface::all`, `DbalGameCatalogQuery`, `PublicGameCatalogController` (`?all=1`), functional test.

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-28-steam-library-coupling.md]
- Current page/catalog: [Source: frontend/src/app/(public)/jeux/page.tsx], [Source: frontend/src/features/games/public-games-api.ts], [Source: frontend/src/features/games/game-card.tsx], [Source: frontend/src/features/games/steam-coupling-panel.tsx]
- Backend catalog: [Source: api/src/GameSelection/Infrastructure/DbalGameCatalogQuery.php], [Source: api/src/GameSelection/Presentation/PublicGameCatalogController.php]
- Standards: [Source: frontend/AGENTS.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Debug Log References

### Completion Notes List

- Ultimate context engine analysis completed — comprehensive developer guide created.
- Implemented on branch `feature/epic-28-story-5-jeux-page-redesign` (stacked on 28.4).
- Catalog is client-driven: page server-fetches all 592 games via `GET /games?all=1`, then `GamesCatalog` (client) does instant search (150ms debounce) + availability/owned filters + sort + owned badge over the single grid. Coupling logic folded in from the old `steam-coupling-panel.tsx` (deleted).
- Owned-only toggle is gated until a successful coupling; owned-first ordering kicks in when coupled. Pure `filterAndSortGames` extracted + unit-tested.
- Backend `?all=1` returns the flat list (no `meta`); `list()` paginated path unchanged. Verified live: 592 games, 289 with steamAppId.
- Gates green: php-cs-fixer 0, phpstan 0, ddd exit 0, phpunit 1053; FE typecheck/lint/build clean, jest 80 (13 suites).

### File List

**Added (api)**
- `api/tests/Functional/PublicGameCatalogAllTest.php`

**Modified (api)**
- `api/src/GameSelection/Application/GameCatalogQueryInterface.php` (all())
- `api/src/GameSelection/Application/PublicGameCatalog.php` (all())
- `api/src/GameSelection/Infrastructure/DbalGameCatalogQuery.php` (all() + shared select/mapRow refactor)
- `api/src/GameSelection/Presentation/PublicGameCatalogController.php` (?all=1 branch)

**Added (frontend)**
- `frontend/src/features/games/games-catalog.tsx`
- `frontend/src/features/games/games-filter.ts`
- `frontend/src/features/games/games-filter.test.ts`

**Modified (frontend)**
- `frontend/src/features/games/public-games-api.ts` (getAllPublicGames + test)
- `frontend/src/features/games/public-games-api.test.ts`
- `frontend/src/app/(public)/jeux/page.tsx` (client-driven rewrite)

**Deleted (frontend)**
- `frontend/src/features/games/steam-coupling-panel.tsx` (folded into GamesCatalog)
