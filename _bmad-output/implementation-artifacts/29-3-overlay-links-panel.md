# Story 29.3: overlay links panel for operators

Status: review

## Story

As an administrator (or private-run owner),
I want a panel on each session-management surface that gives me the **auto-generated overlay URLs**, a
**live preview**, a **Test** button, and **regenerate / revoke** controls,
so that I can hand a streamer ready-to-paste OBS browser sources and kill access in one click if a URL
leaks.

## Context

Stories 29.1 (token + subscribe) and 29.2 (overlay widgets) make the overlays work; this story is the
**operator-facing** surface that ties them to the three session-management UIs. Because every session
type already exposes a `sessionId` in its admin/owner page, a **single** `overlay-links-panel` component
is dropped into all three - generic, no per-type branching.

The panel issues/rotates the opaque token via `POST /sessions/{id}/overlay-token` and revokes via
`DELETE`. For each widget it builds the OBS URL
`{appUrl}/o/{sessionId}/{widget}?t={opaque}` (plus any chosen params), shows recommended OBS dimensions,
and renders a live `<iframe>` preview of the same URL. The **Test** button opens/refreshes the preview
with `?demo=1` so the streamer can position the source without a live event.

## Acceptance Criteria

1. **Component.** `features/overlay/overlay-links-panel.tsx` takes `{ sessionId }` and renders one block
   per widget (`notifications`, `goals`, `log`): a copyable read-only URL, recommended OBS dimensions
   (e.g. notifications `420×140`, goals `800×450`, log `460×640` - tune during build), and a live iframe
   preview. Named export (AC-CO3), explicit `Props` type (AC-CO2), pure render (AC-CO1).
2. **Token issuance.** On first open with no active token, the panel can **Generate** a token
   (`POST /sessions/{id}/overlay-token`); the returned opaque token is injected into every URL. Fetch
   functions live in `features/overlay/overlay-api.ts`, return typed result or `null`, use `env.apiBaseUrl`
   (AC-API1/2/3), and validate with an `is*` guard.
3. **Copy.** Each URL has a copy-to-clipboard button with clear copied feedback. URLs are absolute (built
   from the app origin via `env`, not hardcoded).
4. **Preview + Test.** Each block embeds an `<iframe>` of the widget URL. A **Test** button reloads that
   preview with `?demo=1` (client-side fake event) so the operator sees the animation without a live
   session. No backend test endpoint is used.
5. **Regenerate / Revoke.** A **Regenerate** action rotates the token (re-issue) - old URLs stop working
   on next reconnect, new URLs are shown. A **Revoke** action (`DELETE`) disables all overlay URLs for the
   session and the panel returns to the "Generate" state. Both confirm before acting (destructive for live
   streamers).
6. **Placement.** The panel is embedded in: the **admin event dashboard** session view, the **admin
   weekly-run** admin surface, and the **private-run owner** page - each passing its in-scope `sessionId`.
   Authorization is enforced server-side by 29.1; the panel is only rendered where the user already has
   session-management access (no new client-side gate invented).
7. **Param hints (optional, non-blocking).** The panel may expose a few common toggles (slot filter,
   scale, position, sound, chroma `bg`) that append the corresponding query params to the generated URLs
   and the preview. If included, controls are local UI state (`useState`), not server state.
8. **Gates green.** `pnpm typecheck`, `pnpm lint` (0 warnings), `pnpm build` clean.

## Tasks / Subtasks

- [x] Task 1 - API client (AC 2, 5).
  - [x] `overlay-api.ts` extended with `issueOverlayToken` (POST) / `revokeOverlayToken` (DELETE) via
        `apiFetch` (authenticated), inline `in`-narrowing of the issue response (no `as`).
- [x] Task 2 - Panel component (AC 1, 3, 4).
  - [x] Per-widget URL builder, copy-to-clipboard with feedback, recommended OBS dimensions, live iframe
        preview on a checkerboard (so transparent overlays are visible), "Rejouer (démo)" replay.
- [x] Task 3 - Token lifecycle UI (AC 2, 5).
  - [x] Generate / Regenerate / Revoke with `window.confirm` guards; opaque token persisted in
        `localStorage` (operator's browser) so reloads keep the same URLs; cleared on revoke.
- [x] Task 4 - Wiring (AC 6).
  - [x] `personal-run-detail-page.tsx` (owner/admin, after `PlayerProgressGrid`) and
        `AdminSessionDetailPage` (admin event session detail). **Weekly deferred** - see Dev Agent Record.
- [x] Task 5 - Gates (AC 8).
  - [x] `pnpm typecheck` / `pnpm lint` (0/0) / `pnpm build` clean.

## Dev Notes

### Project Structure Notes

- Lives in `frontend/src/features/overlay/` alongside the 29.2 widgets and API. Reuse the 29.2
  `overlay-api.ts` module (extend it with issue/revoke rather than duplicating).
- Build absolute overlay URLs from the app origin (via `env`), not relative paths, since they are pasted
  into OBS on another machine.

### References

- `frontend/src/features/admin/admin-event-dashboard.tsx` - admin event session surface (placement).
- `frontend/src/features/admin/*weekly-run*` - weekly-run admin surface (placement; confirm exact file).
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx` - private-run owner page (placement;
  already wires `sessionId`).
- Story 29.1 - `POST`/`DELETE /sessions/{id}/overlay-token` contract.
- Story 29.2 - overlay route `/o/{sessionId}/{widget}` + `demo=1` and query params.

## Dev Agent Record

- **Preview defaults to demo mode.** A live overlay URL shows nothing until an event fires, so the
  iframe preview loads `?demo=1` (renders the animation immediately, no token needed) on a CSS
  checkerboard so transparency is visible. The **copyable** URL is the clean live URL (no `demo`). A
  "Rejouer (démo)" button bumps an iframe `key` to replay.
- **Opaque token persisted client-side.** The raw opaque token only exists at issuance (the DB stores a
  hash), so the panel keeps it in `localStorage[archilan_overlay_token_<sessionId>]` to survive reloads
  without re-issuing (which would invalidate URLs already pasted into OBS). Revoke clears it.
- **Two placements wired, weekly deferred (intentional).**
  - Private run: `personal-run-detail-page.tsx`, gated `run.sessionId && (run.isOwner || admin) &&
    (active|idle)` - the owner self-serves ("mes parties").
  - Event: `AdminSessionDetailPage` (admin event session detail) after `SessionDetail`.
  - **Weekly run** is *not* wired: there is no admin weekly-session detail surface, and 29.1's authz
    (`isUserAuthorizedForSession`) does **not** authorize a weekly-entry member to issue a token (weekly
    is admin-managed per the epic). Wiring a weekly member page would ship a button that 403s. Follow-up
    options: (a) extend 29.1 authz to the weekly-entry owner + embed on the member weekly page, or
    (b) add an admin weekly-session surface. Left out rather than shipped broken.
- **`apiFetch` for issue/revoke** (authenticated, owner/admin) vs plain `fetch` for the public subscribe
  in 29.2 - same module, two access models.

### Quality gates (all green)

- `pnpm typecheck` → 0 errors. `pnpm lint` → 0 errors / 0 warnings (after fixing
  `react-hooks/set-state-in-effect` via `requestAnimationFrame` and escaping JSX apostrophes).
  `pnpm build` → clean.

### Files

- `frontend/src/features/overlay/overlay-links-panel.tsx` (new)
- `frontend/src/features/overlay/overlay-api.ts` (extended: issue/revoke)
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx` (embed)
- `frontend/src/features/admin/admin-session-page.tsx` (embed in `AdminSessionDetailPage`)

## Change Log

- 2026-06-16 - Story created (status: planned).
- 2026-06-16 - Implemented: `OverlayLinksPanel` (generate/regenerate/revoke, copy URLs, demo iframe
  preview) wired into the private-run owner page and the admin event session detail. Weekly placement
  deferred (authz/surface gap, documented). All frontend gates green (status: review).
- 2026-06-16 - Refinement (user feedback): cards now stack **one per line** (preview left, controls
  right) so they no longer overflow the panel. The preview is the **live** overlay (no longer demo),
  and each card has a **"Tester en direct"** button that publishes a real event via the 29.1
  overlay-test endpoint - the live preview and any real OBS source react, but player progression pages
  do not. Added `testOverlayEvent` to `overlay-api.ts`.
- 2026-06-16 - Refinement (user feedback): the panel grew dense (token controls + custom-group picker +
  3 widgets × many per-slot links from 29.5), so it was reorganized into **tabs per widget**
  (Notifications / Objectif / Log) - only the active widget's preview + link list shows at once. Extracted
  local `GroupPicker` and `WidgetCard` sub-components (explicit `Props`, no new files) for readability;
  token header + tab bar stay in `OverlayLinksPanel`. Behaviour unchanged. Frontend gates green.
- 2026-06-16 - Reworked the scope picker (user feedback): replaced the per-player link list with a single
  `<select>` (Tous les joueurs / Groupe personnalisé / one slot); the group checkboxes show only when
  "Groupe personnalisé" is picked, and the widget shows one URL for the selected scope (preview reflects
  it). Tabs per widget retained. Also fixed the panel overflow (link list `grid`→`flex flex-col`).
- 2026-06-16 - Fix (user feedback, verified in-browser): a stale/revoked persisted token made every
  overlay + the preview fail to subscribe **silently**. The panel now validates the stored token on load
  (via the public subscribe endpoint) and shows a "régénère" warning when invalid - without auto-clearing
  it (a transient network error must not wipe URLs already in OBS). Generate clears the warning.
- 2026-06-16 - **Token now retrievable across browsers (user feedback, verified in-browser).** Root cause
  of the recurring "invalid token": the raw token lived only in the issuing browser's `localStorage`
  (DB stored a hash), so a second browser saw "Générer" and issuing there **rotated** the token, killing
  the first. Fix: the active raw token is now stored server-side and fetched on load
  (`GET /sessions/{id}/overlay-token` → `ActiveOverlayTokenQuery`), so every browser/device of the owner
  shows the **same** URL with no rotation. Dropped the `localStorage` persistence + the now-moot
  validation warning. (Backend model change recorded in 29.1.)
- 2026-06-16 - **Tokenless permanent URLs (user feedback).** The streamer had to re-paste OBS sources on
  every revoke. Decision: overlays are read-only and on-stream anyway, so drop the token. The panel now
  shows **permanent URLs** (`/o/{session}/{widget}?slot=…`, no `?t=`), always available - no
  Generate/Régénérer/Révoquer, no token state. `overlayUrl` drops `t`; `useOverlayStream`/widgets/overlay
  page drop the token; subscribe is tokenless (29.1). The streamer pastes once, forever.
- 2026-06-16 - Fix (verified in-browser via devtools): the per-scope link list overflowed its frame on
  the narrow private-run page. Root cause: the list was a `grid` whose `auto` column sized to the full
  URL's max-content, so the inputs never shrank (scrollWidth 325 in a 281px column). Switched it to
  `flex flex-col` so the `min-w-0 flex-1` inputs shrink to the column; also card stacks below `lg` and
  the preview is `max-w-full`. No more horizontal overflow (measured scrollWidth == clientWidth).
