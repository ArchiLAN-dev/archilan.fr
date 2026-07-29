# Story 32.2: Share card / OG image for the public session recap

Status: review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a player or visitor sharing a recap link,
I want `/parties/{sessionId}` to unfurl with a generated share card (podium + headline superlative),
so that recap links look compelling on Discord / X / social feeds and pull the French-speaking
community toward ArchiLAN.

Second story of Epic 32 - *Récap de partie*. Pure follow-on of Story 32.1: it consumes the
persisted `SessionRecap` projection through the existing public read path and adds **one generated
image**. This is the highest-leverage remaining Epic 32 story for the community/growth mission:
the recap page already exists, but its links unfurl with text only.

Depends on: 32.1 (recap projection + `GET /api/v1/parties/{sessionId}/recap` + `/parties/[sessionId]` page).

## Context

- Story 32.1 shipped `frontend/src/app/(public)/parties/[sessionId]/page.tsx` with
  `generateMetadata` that already sets `metadataBase`, `openGraph.*` and
  `twitter.card = "summary_large_image"` - but **no image**. Crawlers currently render a bare text
  card.
- **This story is frontend-only.** No api/ change: the card reads the existing public recap
  endpoint through the existing cached fetcher `getSessionRecap` (`features/recap/recap-api.ts`).
  Do not add an endpoint, a fetch function, or any parsing.
- The frontend is **Next.js 16.2.11** (not 15 - see `frontend/AGENTS.md` warning). The relevant
  file convention and API are documented in the packaged docs and MUST be read before coding:
  - `node_modules/next/dist/docs/01-app/03-api-reference/03-file-conventions/01-metadata/opengraph-image.md`
  - `node_modules/next/dist/docs/01-app/03-api-reference/04-functions/image-response.md`

### Architecture decisions (locked)

1. **File convention, not a hand-rolled route.** Create
   `src/app/(public)/parties/[sessionId]/opengraph-image.tsx` with `export const alt`,
   `export const size = { width: 1200, height: 630 }`, `export const contentType = "image/png"`
   and a default-exported async `Image({ params })` returning an `ImageResponse` (imported from
   `next/og`). Next injects `og:image` + dimensions into the page head automatically
   (`metadataBase` is already set by the page). `params` is a **Promise** in Next 16 - `await` it.
2. **Data = `getSessionRecap(sessionId)`, nothing else.** It is `React.cache()`-wrapped
   (AC-NX3) and returns `SessionRecap | null`, never throws. Its `cache: "no-store"` fetch makes
   the image route dynamic (rendered per request); that is acceptable - only crawlers request it.
   Do not add `revalidate` in this story.
3. **Rendering = satori subset.** `ImageResponse` supports **flexbox only** (no grid), inline
   `style={{}}` objects only. This file is a documented exception to AC-CSS1 (like the `<canvas>`
   components): Tailwind classes and CSS custom properties do **not** exist inside satori. Copy the
   needed hex values from the design tokens in `src/app/globals.css` into local constants.
4. **Fonts.** Satori accepts `ttf`/`otf`/`woff` only (no woff2), so `next/font/google`
   (`src/app/fonts.ts`) is unusable here. Commit `SpaceGrotesk-SemiBold.ttf` (heading font of the
   site, OFL-licensed) next to the route and load it with
   `readFile(join(process.cwd(), "src/app/(public)/parties/[sessionId]/SpaceGrotesk-SemiBold.ttf"))`
   - the literal `join(process.cwd(), ...)` pattern is what Next's file tracing follows into the
   standalone Docker output, keep it literal. Body text falls back to the font bundled with
   `next/og`. Total asset budget for the route is 500 KB - a single subsetted TTF fits.
5. **Never fail the unfurl.** `getSessionRecap` returning `null` (pre-32.1 session without
   projection, not-finished, private, network error) must still produce a generic branded ArchiLAN
   card - no throw, no `notFound()`, no empty response. The page's own 404/robots handling is
   unchanged.
6. **Pure card-data selection, tested; rendering, not unit-tested.** Extract the card's data
   shaping into a pure module and unit-test that. Do **not** import `opengraph-image.tsx` from a
   jest test: `next/og` drags wasm/native rendering into the node test environment.

## Acceptance Criteria

1. **Route.** `src/app/(public)/parties/[sessionId]/opengraph-image.tsx` exists with `alt`
   (French, meaningful), `size` 1200x630, `contentType` `"image/png"`, and returns an
   `ImageResponse`. The rendered `/parties/{id}` page head contains `og:image` (absolute URL via
   existing `metadataBase`) with width/height/type meta tags. `twitter.card` stays
   `summary_large_image` (X falls back to `og:image`; no separate `twitter-image` file).
2. **Card content (recap available).** ArchiLAN branding + event name, podium **top 3** (rank,
   player name, game, formatted completion time or a "-" placeholder when `completionSeconds` is
   null), **headline superlative** (first entry of `recap.superlatives`, label + winner player
   name), player count and formatted session duration. Dark gaming look consistent with the site
   (token hex values from `globals.css`), Space Grotesk for headings.
3. **Card content (recap unavailable).** Generic branded card (ArchiLAN + "Récap de partie" copy),
   produced through the same route with no crash, for any `null` recap.
4. **Robustness.** Long event/player/game names are truncated or wrapped without breaking the
   1200x630 layout; a podium with fewer than 3 entries renders what exists; an empty
   `superlatives` array drops the headline block cleanly.
5. **Reuse, no duplication.** Duration formatting is extracted from `session-recap-page.tsx` into
   a shared pure module reused by both the page and the card; the card's data shaping
   (`top-3 + headline superlative + counts`) is a pure exported function.
6. **Tests.** Jest unit tests for the pure module: top-3 selection, null `completionSeconds`,
   fewer-than-3 podium, headline superlative pick and empty-superlatives case, duration formatting
   edge cases (>= 1 h, < 1 min). No test imports `next/og`.
7. **Gates green.** `pnpm gates` (typecheck, lint, jest, build). The production build must succeed
   with the route (satori runs at build/request time - a satori-unsupported CSS property fails the
   render, not the type check, so verify the image actually renders).

## Tasks / Subtasks

- [x] **T1 - Shared format helpers (AC #5).** Create `src/features/recap/recap-format.ts`;
  move `formatDuration` (currently private in `session-recap-page.tsx:164`) into it and re-import
  in `session-recap-page.tsx`. Scope note: the other `formatDuration` copies in
  `admin/`, `community/`, `runs/` are out of scope - do not touch them.
- [x] **T2 - Pure card data module (AC #2, #4, #5, #6).** `src/features/recap/share-card-data.ts`
  exporting `buildShareCardData(recap: SessionRecap | null)` returning a plain serializable shape:
  `{ kind: "recap", eventName, podium: [{rank, playerName, game, time}], headline: {label, playerName} | null, playerCount, duration }`
  or `{ kind: "fallback" }`. Resolve superlative winner via `recap.graph.nodes` /
  `recap.podium` by `slotId` (both carry player names; podium is authoritative for display names).
  Unit tests per AC #6 in `share-card-data.test.ts`.
- [x] **T3 - Font asset (AC #2).** Commit `SpaceGrotesk-SemiBold.ttf` (download from Google Fonts,
  latin subset is enough) under `src/app/(public)/parties/[sessionId]/` next to the route, plus a
  short provenance/license note (SIL OFL) in the story's File List or an adjacent `README`.
- [x] **T4 - The route (AC #1, #2, #3, #4).** `opengraph-image.tsx`: await `params`, call
  `getSessionRecap`, `buildShareCardData`, render the card (flexbox-only inline styles, local hex
  constants sourced from `globals.css` tokens, explicit `display: "flex"` on every multi-child
  div - satori errors otherwise), load the TTF via `readFile` + `join(process.cwd(), ...)`,
  pass it in `fonts` with `name: "Space Grotesk"`.
- [x] **T5 - Verify + gates (AC #1, #7).** Manual check with the dev server: `curl -I` (or
  browser) `http://localhost:3000/parties/{id}/opengraph-image` returns `image/png` for a session
  with a recap AND for an unknown id (fallback card); view page source of `/parties/{id}` and
  confirm the `og:image` meta tags. Run `pnpm gates`.

## Dev Notes

### Read these packaged docs first (Next 16, not your training data)

- `opengraph-image.md` (file convention): config exports, `params` **as a Promise** (v16 change),
  the `readFile(join(process.cwd(), ...))` local-asset pattern, static-vs-dynamic behavior.
- `image-response.md`: satori constraints - flexbox only, no `display: grid`, 500 KB bundle cap,
  `ttf`/`otf`/`woff` only, `fonts` option shape.

### Existing pieces to reuse (do not reinvent)

- `getSessionRecap` (`src/features/recap/recap-api.ts:126`) - cached, type-guarded, returns
  `SessionRecap | null`, never throws. The `SessionRecap` shape it returns:
  `eventName`, `durationSeconds`, `podium[] {slotId, playerName, game, completionSeconds, goalReachedAt, ...}`,
  `graph.nodes[] {slotId, slotName, game}`, `superlatives[] {key, label, slotId, value}`.
- `generateMetadata` in `page.tsx` already sets `metadataBase: new URL(env.appUrl)` - required for
  the absolute `og:image` URL; do not duplicate it in the image route.
- Superlative labels are already ArchiLAN pop-culture style (32.1 AC #4) - print them verbatim.

### Satori gotchas (these fail at render time, not compile time)

- Every `<div>` with more than one child needs explicit `display: "flex"`.
- No CSS variables, no Tailwind, no `grid`, no `gap` percentage tricks; `flexDirection`,
  `justifyContent`, `alignItems`, absolute positioning, `borderRadius`, linear gradients are fine.
- Text overflow: constrain with fixed-width flex children + `overflow: "hidden"`,
  `textOverflow: "ellipsis"`, `whiteSpace: "nowrap"` (supported) for single-line truncation.
- The build prerenders nothing here (dynamic route due to `no-store` data), so a render bug shows
  up when the URL is hit - hence the explicit T5 manual check.

### Project standards that apply

- AC-TS2/TS3: no `any`, no `as` at boundaries - `getSessionRecap` already guards; the pure module
  works on the typed `SessionRecap`.
- AC-API1: no new fetch functions; AC-NX2-analog: `params` is a Promise, await it.
- AC-CSS1 exception: inline styles are mandatory inside `ImageResponse` (satori). Keep the
  exception contained to `opengraph-image.tsx`.
- No `Date.now()` / `Math.random()` anywhere in the route (deterministic card).
- Typography rule: no em-dashes in any copy on the card or in code comments.

### Previous story intelligence (from 32.1)

- Pre-existing finished sessions have **no projection** and 404 on the recap endpoint - the
  fallback card (AC #3) covers exactly this case; do not special-case it.
- Display names come from the podium (`RunResultsQuery`, live), while `graph.nodes` carry
  bridge-reconciled `slotName` - prefer podium names when resolving a superlative's `slotId`, fall
  back to the node's `slotName`.
- The recap page renders fine with an edgeless/stats-only recap; the card must too (superlatives
  may hold only the time-based ones, or be empty).

### Project Structure Notes

- Route file must sit in the same segment as the page:
  `src/app/(public)/parties/[sessionId]/opengraph-image.tsx` (file convention scans the segment).
- Pure logic lives in `src/features/recap/` per AC-API1/module layout; no default exports in
  `features/` (AC-CO3) - the default export requirement applies only to the route file itself.

### References

- [Source: _bmad-output/planning-artifacts/epics/epic-32-session-recap.md#Proposed stories] (32.2 definition)
- [Source: _bmad-output/implementation-artifacts/32-1-public-session-recap.md#Dev Agent Record] (projection shape, deferred items)
- [Source: frontend/node_modules/next/dist/docs/01-app/03-api-reference/03-file-conventions/01-metadata/opengraph-image.md]
- [Source: frontend/node_modules/next/dist/docs/01-app/03-api-reference/04-functions/image-response.md]
- [Source: frontend/src/features/recap/recap-api.ts] (SessionRecap type + fetcher)
- [Source: frontend/src/app/(public)/parties/[sessionId]/page.tsx] (generateMetadata, metadataBase)
- [Source: frontend/AGENTS.md] (quality gates, TS/CSS/API standards)

## Dev Agent Record

### Agent Model Used

claude-fable-5 (Claude Code)

### Debug Log References

### Completion Notes List

- **Font: Bold (700) instead of SemiBold.** The upstream repo
  (`floriankarsten/space-grotesk`, source of the Google Fonts family) ships static instances only
  for Light/Regular/Medium/Bold - there is no static SemiBold TTF, and satori's variable-font
  support is not something to rely on. `SpaceGrotesk-Bold.ttf` (113 KB, well under the 500 KB
  route budget) + `SpaceGrotesk-OFL.txt` (license) are committed next to the route.
- **Next 16 serves the generated image on a hashed URL** (`opengraph-image-{hash}?{version}`), not
  the bare `/opengraph-image` path (that one 404s). Verification must extract the URL from the
  page's `og:image` meta tag - done in T5, documented here for future manual checks.
- **Satori layout gotcha found during visual verification:** when the flex column content exceeds
  630 px, children shrink and text blocks get clipped vertically. Fixed with `flexShrink: 0` on the
  title/podium/headline blocks + explicit `lineHeight`. Both card variants were rendered and
  visually inspected (fallback via a dead API base URL; recap variant via a scratchpad mock API
  serving a 4-player payload with a null completion time, an over-long player name and an over-long
  event name - truncation, placeholder and layout all verified on the produced PNGs).
- **TDD respected:** both test suites written first (red - module not found), then the modules
  (green, 14 tests). `formatDuration` moved verbatim out of `session-recap-page.tsx`; the page
  behavior is unchanged.
- Pre-existing lint warning in `src/features/admin/admin-content-dashboard.tsx` (story 33.18,
  `react-hooks/exhaustive-deps`) is on develop and out of this story's scope; `pnpm lint` exits 0.

### File List

**frontend/ (new)**
- `src/features/recap/recap-format.ts` (shared `formatDuration`)
- `src/features/recap/recap-format.test.ts`
- `src/features/recap/share-card-data.ts` (pure card data selection)
- `src/features/recap/share-card-data.test.ts`
- `src/app/(public)/parties/[sessionId]/opengraph-image.tsx` (satori/ImageResponse route)
- `src/app/(public)/parties/[sessionId]/SpaceGrotesk-Bold.ttf` (static instance, upstream repo `floriankarsten/space-grotesk`, SIL OFL 1.1)
- `src/app/(public)/parties/[sessionId]/SpaceGrotesk-OFL.txt` (license text)

**frontend/ (modified)**
- `src/features/recap/session-recap-page.tsx` (imports `formatDuration` from `recap-format`, local copy removed)

## Change Log

| Date       | Change |
|------------|--------|
| 2026-07-25 | Story implemented: OG share card for `/parties/{sessionId}` via the Next 16 `opengraph-image.tsx` file convention. Pure data module + 14 unit tests, Space Grotesk Bold committed (OFL), both card variants rendered and visually verified. |
