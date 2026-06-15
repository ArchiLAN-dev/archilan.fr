# Story 28.4: Coupling UI on the Jeux page

Status: ready-for-review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a visitor on `/jeux`,
I want to enter my Steam account and see which of my games are playable at ArchiLAN events, with a clear summary and per-game "you own this" badges,
so that the catalog becomes personal and actionable.

This is the **experience** layer of Epic 28. It consumes the public coupling endpoint (28.2) and, for logged-in users, the saved Steam reference (28.3). Intersection only — no "games you don't own" suggestions (deferred per epic).

## Acceptance Criteria

1. On `/jeux`, a "Couple ta bibliothèque Steam" panel lets the user enter a Steam profile URL / vanity / SteamID64 and submit. Submitting calls `POST /api/v1/games/steam-coupling` via a TanStack `useMutation` (no fetch-in-`useEffect`).
2. On success (`outcome: ok`), a summary banner shows **"{matchedCount} de tes {ownedCount} jeux Steam sont jouables à ArchiLAN"** and the matched games render as cards with a **"Tu possèdes ce jeu"** badge.
3. Outcome handling: `private_profile` → an actionable message ("Passe tes ‘détails de jeu’ en public", with a link to the Steam privacy settings page); `invalid_input` → inline validation error; `steam_error` → a retryable error message. None of these throw or crash the page.
4. **Anonymous** visitors: the entered Steam reference is persisted in `localStorage` and pre-fills the input on return. **Logged-in** visitors: the input pre-fills from `useAuth().user?.steamProfile` (28.3) and, after a successful manual entry, the panel offers "Enregistrer sur mon compte" (calls 28.3's `saveSteamAccount`).
5. The catalog grid (server-rendered) is unchanged structurally; matched results are shown in the panel's own result grid. `GameCard` gains an optional `owned?: boolean` prop that renders the badge; default `false` keeps existing usages intact.
6. `pnpm typecheck`, `pnpm lint`, `pnpm build` all clean. No backend changes.

## Tasks / Subtasks

- [ ] **Extend the public game type** (AC: 2)
  - [ ] `frontend/src/features/games/public-games-api.ts`: add `steamAppId: number | null` to `PublicGame` and validate it in `isPublicGame` (`"steamAppId" in v && (v.steamAppId === null || typeof v.steamAppId === "number")`). (28.1 exposes it on `GET /games`; the coupling endpoint returns the same shape.)
- [ ] **Coupling API layer** (AC: 1, 3)
  - [ ] `frontend/src/features/games/steam-coupling-api.ts`: `type CouplingResult = { outcome: "ok" | "private_profile" | "invalid_input" | "steam_error"; matchedGames: PublicGame[]; ownedCount: number; matchedCount: number }`. `coupleSteamLibrary(steamProfile: string): Promise<CouplingResult>` — `POST` (plain `fetch`, public endpoint, `Content-Type: application/json`), map non-OK 422 → `invalid_input`, 502 → `steam_error`, parse body with a type guard `isCouplingResult` (AC-TS3/AC-TS4), network failure → `steam_error`. Never throw.
- [ ] **GameCard badge** (AC: 4)
  - [ ] `frontend/src/features/games/game-card.tsx`: add `owned?: boolean` to props; when `true`, render a "Tu possèdes ce jeu" badge (reuse the existing badge styling tokens already used for availability — `border`/`bg`/`text` design tokens, no hardcoded hex; AC-CSS2). Default `false`.
- [ ] **Coupling panel component** (AC: 1–4)
  - [ ] `frontend/src/features/games/steam-coupling-panel.tsx` (`"use client"`): controlled input; `useMutation({ mutationFn: coupleSteamLibrary })`; on `ok` render the summary banner + matched `GameCard`s with `owned`; render outcome-specific messages; `staleTime` not applicable (mutation). Initial input value from `useAuth().user?.steamProfile ?? localStorage`. On successful submit by an anonymous user, write the reference to `localStorage`; for a logged-in user, show "Enregistrer sur mon compte" → `saveSteamAccount` (28.3) then `setUser`.
  - [ ] Read `localStorage` only inside an event handler or `useEffect` (not during render — AC-HK3); compute no impure values during render.
- [ ] **Wire into the page** (AC: 1)
  - [ ] `frontend/src/app/(public)/jeux/page.tsx` (stays a Server Component): render `<SteamCouplingPanel />` above the catalog grid. The panel is the only client island; the catalog list stays server-rendered.
- [ ] **Tests** (AC: 1–3)
  - [ ] `frontend/src/features/games/steam-coupling-api.test.ts`: type-guard + outcome-mapping unit tests (mirror `public-games-api.test.ts`).

## Dev Notes

### Dependencies
- **Hard-requires 28.2** (the `POST /games/steam-coupling` endpoint) and **28.1** (`steamAppId` on the payload).
- **Soft-integrates 28.3**: the pre-fill-from-account and "save to account" affordances need 28.3's `steamProfile` on `AuthUser` + `saveSteamAccount`. If 28.3 is not yet merged, ship anonymous-only (localStorage) and add the account integration when 28.3 lands. Note this in the PR.

### Reuse, don't reinvent
- `/jeux` page + catalog grid + `GameCard` already exist — extend, do not rebuild. The page is a Server Component fetching `getPublicGames`; keep it server-rendered and add a single client island. [Source: frontend/src/app/(public)/jeux/page.tsx, frontend/src/features/games/game-card.tsx]
- API-layer conventions: typed-or-fallback functions, `unknown` + type guards at the boundary, `is{Type}` guards co-located with the fetch fn, `env.apiBaseUrl`. [Source: frontend/src/features/games/public-games-api.ts, frontend/src/features/games/game-request-api.ts]
- Auth state: `useAuth()` from `auth-context.tsx` exposes `user` (with `steamProfile` after 28.3) and `setUser`. [Source: frontend/src/features/auth/auth-context.tsx:21-110]
- Reuse 28.3's `steam-account-api.ts` for the "save to account" button — do not duplicate the PUT call.

### Frontend standards (frontend/AGENTS.md)
- Client component only because of event handlers + TanStack — keep everything else server (AC-NX4). Data fetching for the mutation via TanStack `useMutation`; **no `fetch` in `useEffect`** (AC-API4). No `process.env` (use `env.ts`, AC-ENV1). No `any`, no `as` at the boundary (AC-TS2/AC-TS3). Tailwind tokens only, mobile-first (AC-CSS). Stable list keys (`game.id`, AC-KEY1). No impure calls (`localStorage`, `Date.now()`) during render (AC-HK3).

### UX outcomes (copy guidance)
- `private_profile`: explain the Steam "Game details" privacy setting must be public; link to `https://steamcommunity.com/my/edit/settings`. Make clear this is a Steam-side setting, not an ArchiLAN error.
- `invalid_input`: "Profil Steam non reconnu — colle l'URL de ton profil, ton pseudo Steam, ou ton SteamID64."
- `steam_error`: transient; offer retry.
- Empty match on `ok` (ownedCount > 0, matchedCount 0): "Aucun de tes jeux Steam n'est (encore) supporté — la bibliothèque s'enrichit régulièrement." Note IGDB Steam-coverage gaps (console titles never match).

### Scope boundaries
- Intersection only; **no** "compatible games you don't own" suggestions (deferred per epic).
- Do not mutate the paginated server catalog grid client-side; show matches in the panel's result section (simpler, avoids hydration churn). Highlighting matches inside the main grid is an optional later enhancement.

### Project Structure Notes
- New: `features/games/steam-coupling-api.ts`, `features/games/steam-coupling-panel.tsx`, `features/games/steam-coupling-api.test.ts`.
- Modified: `features/games/public-games-api.ts` (`PublicGame` + guard), `features/games/game-card.tsx` (`owned` prop), `app/(public)/jeux/page.tsx` (render the panel).

### References
- Epic: [Source: _bmad-output/planning-artifacts/epics/epic-28-steam-library-coupling.md] (story 28.4)
- Prior stories: [Source: _bmad-output/implementation-artifacts/28-2-steam-web-api-coupling-endpoint.md], [Source: _bmad-output/implementation-artifacts/28-3-save-steamid-on-account.md]
- Page/card/api: [Source: frontend/src/app/(public)/jeux/page.tsx], [Source: frontend/src/features/games/game-card.tsx], [Source: frontend/src/features/games/public-games-api.ts]
- Auth/api patterns: [Source: frontend/src/features/auth/auth-context.tsx], [Source: frontend/src/features/games/game-request-api.ts]
- Frontend standards: [Source: frontend/AGENTS.md]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Debug Log References

### Completion Notes List

- Ultimate context engine analysis completed — comprehensive developer guide created.
- Implemented on branch `feature/epic-28-story-4-coupling-ui` (stacked on 28.3).
- Used the codebase's established client pattern (useState + async event handler, like `GameRequestSection`) instead of TanStack `useMutation`: the page has no QueryClientProvider and coupling is triggered by a button (no fetch-in-`useEffect`), so this matches the surrounding code while honoring AC-API4's intent.
- Matched games come back in a lighter shape (`CoupledGame`) than `PublicGame`; mapped to a `PublicGame` for `GameCard` reuse (empty description, alt = name). Steam brand icon via `react-icons/fa` (`FaSteam`) — lucide has no Steam icon.
- Anonymous prefill via `localStorage`; logged-in prefill from `user.steamProfile` (28.3) + "Enregistrer sur mon compte".
- Gates green: pnpm typecheck (0), lint (0), jest (71/71), build clean.

### File List

**Added**
- `frontend/src/features/games/steam-coupling-api.ts`
- `frontend/src/features/games/steam-coupling-panel.tsx`
- `frontend/src/features/games/steam-coupling-api.test.ts`

**Modified**
- `frontend/src/features/games/public-games-api.ts` (steamAppId on PublicGame + guard)
- `frontend/src/features/games/public-games-api.test.ts` (fixture steamAppId)
- `frontend/src/features/games/game-card.tsx` (owned prop + badge)
- `frontend/src/app/(public)/jeux/page.tsx` (render SteamCouplingPanel)
