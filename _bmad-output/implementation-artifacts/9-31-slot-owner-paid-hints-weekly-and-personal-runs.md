# Story 9.31: Slot-owner paid hints on the weekly-run & personal-run pages

**Status:** review
**Date:** 2026-06-14

## Story

As a **player** viewing my own run (weekly-run `ma-run` page, or my personal-run slot page),
I want **paid** hint buttons on the Items (item) and Checks (location) lists that spend **my own**
slot's AP hint points,
So that I can buy a hint without needing an admin — and **without** any free/admin hint path on these
player-facing pages.

## Context

Story 9.30 added the **paid** hint mechanism end-to-end (bridge `run_self_hint` → ephemeral
connect-as-slot → charge the slot's points; API proxy; bridge-client `requestHint`/`requestHintItem`).
But it deliberately:

- wired the frontend **only** on the session surfaces (`/sessions/{id}/slots/...`): the admin
  reachability page and the personal-run slot page, **gated `isAdminUser`**
  (`personal-run-slot-detail-page.tsx:857,1139`);
- kept `requireAuthenticatedAdmin` on the paid API endpoints (9.30 **AC#8**: *"Relaxing to slot-owner
  is out of scope for this story"*).

Net effect today: **no non-admin player can request a paid hint anywhere**, and the **weekly-run**
`ma-run` page has **no hint buttons at all** — `WeeklyRunSlotStateController` exposes only GET proxies
(`reachable`, `hints`, `item-locations`, tokens, `players`); there is **no POST hint-request endpoint**
for weekly runs, and `weekly-run-slot-page.tsx` renders `ItemListPanel`/`CheckListPanel` without
`onHintRequest` (`:784`, `:757`). (Reported by Jean.)

This story closes both gaps for the **slot owner**, on **two** surfaces, **paid-only** (no free/admin
button on player pages):

1. **Weekly-run `ma-run`** — owner is established by `WeeklyRunSlotQuery::findLaunchedEntryInfo` (already
   returns `forbidden` for a non-owner non-admin). New POST endpoints proxy to the bridge with
   `free: false`.
2. **Personal-run slot page** (`/runs/{runId}/slots/{slotId}` → session surface) — relax the session
   paid endpoints from admin-only to **slot owner OR admin**, and show the paid buttons to the owner.

> Scope decided with the user (2026-06-14): both surfaces; items **and** checks; **paid only** on the
> player pages (the free/admin toggle stays only on the admin reachability page).

## Acceptance Criteria

1. **Weekly-run API — location.** `POST /api/v1/weekly-runs/{runId}/entries/{entryId}/slots/{slotIndex}/hints/request`
   with `{ location_id }` authorizes via `findLaunchedEntryInfo` (owner or admin; `forbidden`→403,
   `not_found`→404, not-launched→409), proxies to the bridge as a **paid** hint (`free=false`,
   `requestHint`) and returns the same shape as the session endpoint. The owner is **never** offered the
   free/admin path on this surface.
2. **Weekly-run API — item.** `POST …/slots/{slotIndex}/hints/request-item` with `{ itemName }` does the
   same via `requestHintItem` (`free=false`). Empty `itemName`→422.
3. **Weekly-run frontend.** `weekly-run-slot-page.tsx` passes `onHintRequest` (paid; `hintFree={false}`)
   and `hintCost={hints?.hintCost ?? 0}` to **both** the Items (not-received) `ItemListPanel` and the
   Checks (reachable + unreachable) `CheckListPanel`, calling the new endpoints. No admin toggle here.
4. **Personal-run authz relaxed.** The session paid endpoints (`PlayerStateController::requestHint` /
   `requestHintItem`) authorize **slot owner OR admin** (was admin-only). Ownership is determined by an
   Application query (no DB access in the controller). A non-owner non-admin gets 403. The bridge `free`
   flag is forced to `false` for a non-admin caller (a player can never trigger the free/admin path).
5. **Personal-run frontend.** On `/runs/{runId}/slots/...` the paid hint buttons are shown to the **slot
   owner** (not only admins): `onHintRequest` wired for non-admin owners with `hintFree={false}`; the
   admin free/paid toggle remains for admins only.
6. **Insufficient points / bridge reject** surfaces as the 9.30 behaviour (409/502 from the bridge ack);
   the UI shows the failure and does not double-count. The created hint arrives via the data-storage
   push (story 9.27) — no optimistic add.
7. **Gates green.** API: phpstan / php-cs-fixer / phpunit / `app:architecture:ddd`. Frontend:
   typecheck / lint / build. New functional/unit coverage for the weekly endpoints and the relaxed
   session authz (owner allowed, stranger 403).

## Tasks / Subtasks

- [x] **Task 1 — Weekly-run API endpoints** (AC 1, 2)
  - [x] 1.1 Added `requestHint` (location) + `requestHintItem` (item) POST actions to
    `WeeklyRunSlotStateController`, authorized by `findLaunchedEntryInfo`, proxying via
    `BridgeClientPool->get($externalSessionId, http://host:bridgePort)->slots()->requestHint/requestHintItem(...)`
    with `free=false`. Injected `BridgeClientPool` (no services.yaml change — autowired; `$runnerBaseUrl`
    still bound by name).
  - [x] 1.2 Body validation (`location_id` int>0 / `itemName` non-empty) → 422; bridge failure → 503.
- [x] **Task 2 — Session authz relax for the owner** (AC 4)
  - [x] 2.1 Reused the existing `SessionQuery::isUserAuthorizedForSession` (admin OR active event
    registration OR personal-run owner/participant) via the controller's `isAuthorized()` helper — the
    same check the GET reachable/item-locations endpoints already use. No new query needed; stays within
    DDD (controller calls the Application query, no DBAL).
  - [x] 2.2 `PlayerStateController::requestHint`/`requestHintItem`: `requireAuthenticatedUser` +
    `isAuthorized` (stranger → 403); `$free = isAdmin && body.free` so a non-admin can never trigger the
    free/admin path. Added an `isAdmin()` helper.
- [x] **Task 3 — Weekly-run frontend** (AC 3)
  - [x] 3.1 `weekly-run-slot-page.tsx`: `handleHintLocation`/`handleHintItem` POST to the new endpoints
    (always `free:false`); `onHintRequest` + `hintCost` + `hintFree={false}` on the Items (not-received)
    and both Checks panels.
- [x] **Task 4 — Personal-run frontend** (AC 5)
  - [x] 4.1 `personal-run-slot-detail-page.tsx`: dropped the `isAdminUser ? … : undefined` gate on
    `onHintRequest` (item + both location panels) so the slot owner also gets the buttons;
    `hintFree={isAdminUser ? hintFree : false}` kept (non-admin always pays); admin free/paid toggle and
    `HintsPanel` toggle stay admin-only.
- [~] **Task 5 — Tests** (AC 7) — **deferred**, following the 9.30 precedent: the paid endpoints proxy
  to the bridge and there is no bridge-mock harness for these controllers (9.30 explicitly skipped the
  functional test for the sibling `requestHint` proxy for the same reason). The authz branch (stranger
  403 / bad body 422) returns before any bridge call and is a sensible follow-up to add once a bridge
  mock exists. **Noted as the main open item for this story.**
- [x] **Task 6 — Quality gates** (AC 7)
  - [x] API: phpstan ✓ / php-cs-fixer ✓ / phpunit 1015 ✓ / `app:architecture:ddd` ✓
  - [x] Frontend: typecheck ✓ / lint ✓ / build ✓

## Dev Notes

- **No bridge change.** The bridge already has `run_self_hint` + `/hints/{slot}/request` /
  `request-item` (9.30); the `archilan/bridge-client` package already exposes `requestHint` /
  `requestHintItem`. This story is **API + frontend only**.
- **Paid-only on player pages.** Never send `free:true` from the weekly page or from a non-admin caller.
  The free/admin path (`!admin /hint…`, stories 9.28/9.29) stays exclusive to the admin reachability
  page.
- **DDD:** controller stays thin — ownership check goes through an Application query interface, not a
  DBAL call in Presentation (AC-P1/P2). The weekly controller already injects what it needs except the
  bridge-client pool — add `BridgeClientPool` (Infrastructure dependency injected via interface/service).
- **Hint cost / display** reuse `hints?.hintCost` already fetched on both pages; `HintButton` already
  renders the cost when `free=false`.

### Project Structure Notes

- `api/src/WeeklyRuns/Presentation/WeeklyRunSlotStateController.php` (new POST actions)
- `api/src/Sessions/Presentation/PlayerStateController.php` (authz relax) + new ownership query
  (`Application` interface + `Infrastructure` DBAL impl)
- `frontend/src/features/weekly-runs/weekly-run-slot-page.tsx`
- `frontend/src/features/personal-runs/personal-run-slot-detail-page.tsx`
- `frontend/src/features/reachability/check-panels.tsx` (no change expected — already supports the props)

### References

- [Source: _bmad-output/implementation-artifacts/9-30-paid-hint-via-connect-as-slot.md] — paid mechanism + AC#8 deferral
- [Source: api/src/Sessions/Presentation/PlayerStateController.php:263] — `requestHint`/`requestHintItem` proxy to mirror
- [Source: api/src/WeeklyRuns/Presentation/WeeklyRunSlotStateController.php:30] — weekly GET proxies + `findLaunchedEntryInfo` owner check
- [Source: frontend/src/features/weekly-runs/weekly-run-slot-page.tsx:757] — panels rendered without `onHintRequest`
- [Source: frontend/src/features/personal-runs/personal-run-slot-detail-page.tsx:857] — `isAdminUser` gating to relax
- Stories 9.27 (data-storage hint push) · 9.28/9.29 (free/admin path, unchanged)

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-14 | Created (draft). Scope set with the user: paid hints for the slot owner on the weekly-run `ma-run` page (items + checks) and on the personal-run slot page; paid-only on player surfaces. Closes the slot-owner authz deferred by 9.30 AC#8. |
| 2026-06-14 | Implemented (API + frontend). Weekly endpoints added; session paid endpoints relaxed to owner-or-admin (reusing `isUserAuthorizedForSession`) with `free` forced false for non-admins; both frontends wired (paid-only). All 7 gates green (API phpstan/cs-fixer/phpunit 1015/ddd, frontend typecheck/lint/build). Tests (Task 5) deferred per 9.30 precedent (no bridge mock) — sole open item. Status → review. |
