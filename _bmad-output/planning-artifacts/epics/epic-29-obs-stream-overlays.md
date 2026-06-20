# Epic 29 - OBS Stream Overlays

Status: planned (not started)
Date: 2026-06-16

## Goal

Give streamers and casters **read-only browser-source overlays** for OBS that surface the same
Archipelago notifications already shown on the player progression pages - **item notifications**,
**goal-achieved celebrations**, and a **live event log/history** - without exposing any authenticated
action or letting OBS log in.

The overlays are **generic across all three session types** (private game, event, weekly-run entry)
because every type resolves to a single `sessionId`. One overlay stack is built on `sessionId`; each
session-management surface simply exposes the auto-generated URLs.

## Decisions (locked)

- **Read-only only.** Overlays never trigger admin actions, never expose hint/location spoilers by
  default. They subscribe to Mercure topics and render.
- **Anchor = `sessionId`.** No per-type overlay logic. Authorization to *issue* an overlay token
  mirrors the existing per-type authz already enforced by the `*-token` controllers (admin for
  event/weekly, owner for private run).
- **The feed IS the event log.** Topic `runs/{sessionId}/feed` already carries timestamped events
  (`item-received`, `hint`, `location-checked`, `chat`, `system`) published by the bridge and consumed
  by `EventFeed`. The log widget needs **no new backend event plumbing** - and `item-received` feed
  events are a cleaner toast source than the per-slot reachability diff (one event = one toast).
- **One revocable token per session.** Mercure JWTs are stateless (non-revocable), so we use an
  **opaque → exchange** model: a persisted opaque token (per session, revocable) that a public endpoint
  exchanges for a short-lived Mercure subscriber JWT (subscribe to `feed` + `players`). Revoke = delete
  the opaque row → every overlay for that session dies on its next reconnect.
- **Backend home = `Streaming` context.** The context already exists (Twitch status). It owns the
  overlay token; it reuses `Sessions\SessionQuery` for authorization.
- **Customization via query params**, not rebuilds (OBS-friendly): `slot`, `scale`, `pos`, `duration`,
  `theme`, `sound`, `spoilers`, `bg`. A `demo=1` mode fires a fake notification client-side for
  positioning the source (no backend test endpoint needed).
- **Reuse, don't rebuild.** `ItemToast`, `GoalCelebration`, and the `EventFeed` rendering are reused as
  the overlay widgets. The new code is a bare transparent route group + the token plumbing + the
  operator panel.

## Scope

### In scope
- Backend: opaque overlay token (issue / rotate / revoke) + public subscribe endpoint that mints a
  short-lived Mercure JWT (`Streaming` context).
- Frontend: bare `app/(overlay)/o/[sessionId]/[widget]/page.tsx` route group (transparent, no chrome),
  three widgets - `notifications`, `goals`, `log` - driven by the existing SSE topics, with the query
  params above and `demo=1`.
- Frontend: `overlay-links-panel` (copy URLs + recommended OBS dimensions + live iframe preview + Test)
  embedded in admin event dashboard, admin weekly-run admin, and the private-run owner page.

### Out of scope (open doors, not built here)
- Bonus widgets: standings ticker, BK/blocked alert, full-screen goal takeover (candidate follow-ups).
- Overlay audio mixing beyond the existing fanfare (`?sound=` plays the existing `FanfarePicker` asset).
- Any write/admin capability from an overlay URL.
- Per-widget tokens (we chose one revocable token per session).

## Affected systems (verified)

- **api/ `Streaming`** - currently Twitch status only. Add: `SessionOverlayToken` domain (opaque,
  `revokedAt`), `IssueOverlayToken` / `RevokeOverlayToken` application services, an
  `OverlaySubscribeQuery` (validates opaque → returns Mercure JWT + hubUrl + topics), and two
  controllers (`OverlayTokenController` admin/owner-gated; `PublicOverlaySubscribeController` public).
  Reuses `Sessions\Application\SessionQuery` for authz and `Symfony\Component\Mercure\HubInterface`
  (same `getFactory()->create(subscribe: [...])` pattern as `FeedTokenController`).
- **api/ migration** - new `session_overlay_token` table (`id`, `session_id`, `token`, `created_at`,
  `revoked_at`).
- **frontend/** - new `app/(overlay)/layout.tsx` (transparent, no `PublicShell`) +
  `app/(overlay)/o/[sessionId]/[widget]/page.tsx`; new `features/overlay/` (overlay-api with type
  guard, the three widget components wrapping `ItemToast` / `GoalCelebration` / feed rendering, and
  `overlay-links-panel.tsx`). Panel wired into `admin-event-dashboard.tsx`,
  `admin-weekly-run-*` admin surface, and `personal-run-detail-page.tsx`.
- **Config** - no new env on the frontend (overlay page calls the api/ subscribe endpoint). Reuses the
  existing Mercure hub config on api/.

## Proposed stories

- **29.1 - Enabler: revocable overlay token + public subscribe endpoint (api/, `Streaming`).**
  `SessionOverlayToken` domain (opaque, revocable) + migration; `IssueOverlayToken` (create/rotate),
  `RevokeOverlayToken`; `OverlaySubscribeQuery` that validates a non-revoked opaque token and returns a
  short-TTL Mercure subscriber JWT (subscribe `runs/{id}/feed` + `runs/{id}/players`) + `hubUrl`.
  Controllers: `POST/DELETE /api/v1/sessions/{id}/overlay-token` (authz mirrors the existing `*-token`
  controllers - admin, or owner/participant per session type) and public
  `GET /api/v1/public/overlay/{id}/subscribe?t={opaque}`. Unit + functional tests. No UI.
- **29.2 - Overlay widgets: bare route group + three widgets (frontend).** `app/(overlay)` transparent
  layout + `o/[sessionId]/[widget]` page. `notifications` (feed `item-received` → `ItemToast` queue),
  `goals` (players `client_status === 30` → `GoalCelebration`), `log` (feed → reused `EventFeed`
  rendering, restyled for overlay). Token flow via the 29.1 subscribe endpoint with the existing
  `onerror` re-mint/reconnect pattern. Query params `slot/scale/pos/duration/theme/sound/spoilers/bg`
  and `demo=1` client-side demo. Gates: typecheck / lint / build.
- **29.3 - Overlay links panel for operators (frontend).** `overlay-links-panel.tsx`: per-widget
  copyable URL (opaque token injected), recommended OBS dimensions, live iframe preview, a **Test**
  button (loads the widget with `demo=1`), and **regenerate / revoke** controls (calls 29.1
  `POST`/`DELETE`). Embedded in admin event dashboard, admin weekly-run admin, and the private-run owner
  page (each already has `sessionId` in scope). Gates: typecheck / lint / build.
- **29.4 - Enrich feed item events with origin: check + world + sender (bridge + relay + frontend).**
  *Amends the "no new backend event plumbing" decision below.* The bridge flattens `ItemSend` into a
  prose `text`; this story attaches structured `item`/`location`/`sender`/`receiver` (reusing data the
  bridge already resolves) so notifications/log/EventFeed show "item - origin check - world (sender)".
  Includes a blocking Task 1: confirm/repair the live feed -> Mercure delivery path (appears unwired in
  the current code) and unify the `item_sent`/`item-received` type value. Per-slot notification filtering
  is split into a later follow-up story.
- **29.5 - Per-slot overlay filter (frontend).** `?slot=N` scopes notifications (items received by the
  slot) and the log (events involving the slot as sender/receiver, global events hidden), reusing 29.4's
  structured `sender`/`receiver`; `goals` already filtered. The links panel gains a slot dropdown
  (from `/players`) that injects `?slot=` into the URLs + preview. Test events bypass the filter.
  Mostly frontend; one small bridge addition enriches `GET /state` with per-slot `game`/`slot_type` so
  the dropdown can exclude the injected TextOnly "Bridge" observer (game "Archipelago").

## Sequencing

Backend enabler first, then OBS-facing widgets, then the operator panel:
`29.1` (token + subscribe) → `29.2` (overlay widgets) → `29.3` (links panel). `29.2` can begin against a
manually-minted token but depends on `29.1` for the real subscribe endpoint; `29.3` depends on both.

## Risks / notes

- **Token leakage:** an overlay URL embeds a long-lived opaque token and will be shared/visible on
  stream. Mitigations: read-only scope, no spoilers by default, and one-click regenerate/revoke per
  session (29.3). Document the trade-off in copy.
- **Unattended longevity:** overlays run for hours. The opaque token must not expire (only the minted
  Mercure JWT is short-lived and re-minted on reconnect, exactly like the existing `*Push`/feed
  reconnect logic). Robust SSE reconnect is mandatory.
- **Transparency in OBS:** rely on alpha (`background: transparent`); offer a chroma-key fallback
  (`?bg=00ff00`) for setups without alpha compositing.
- **Authz parity per type:** 29.1 must reproduce the *exact* per-type authorization already used by
  `FeedTokenController` / `PlayersPushController` token endpoints (admin / event-registration / weekly
  member / private owner) - confirm each during implementation rather than inventing a new rule.
- **Foundation already in place:** `feed`, `players`, `reachable` Mercure topics, the subscriber-token
  minting pattern, and the `ItemToast` / `GoalCelebration` / `EventFeed` components all exist - this
  epic is mostly assembly + one backend brick.

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-16 | Epic planned. Read-only OBS overlays generic over the 3 session types, anchored on sessionId. Backend: opaque revocable overlay token + public subscribe (Streaming context). Frontend: bare (overlay) route group with 3 widgets reusing ItemToast/GoalCelebration/EventFeed + operator links panel. Key finding: the runs/{id}/feed topic already is the timestamped event log → log widget needs no new backend events. Stories 29.1-29.3 proposed. |
| 2026-06-16 | Added story 29.5: per-slot overlay filter (`?slot=`) for notifications + log, reusing 29.4's structured sender/receiver; slot dropdown in the links panel. Frontend-only follow-up. |
| 2026-06-16 | Added story 29.4: enrich feed item events with structured origin (check + world + sender/receiver). Amends the locked "no new backend event plumbing" decision - the feed `text` carries origin only as prose, so the bridge must attach structured fields (data it already resolves) for notifications/log/EventFeed to render "item - origin check - world (sender)". Investigation surfaced a blocking pre-condition: the live feed→Mercure path appears unwired in the current code (no feed-push, no Mercure publisher in the current bridge, publisher-token uncalled) plus an `item_sent`/`item-received` type mismatch → both settled in 29.4 Task 1. Per-slot "only my notifications" filtering split into a separate follow-up. |
