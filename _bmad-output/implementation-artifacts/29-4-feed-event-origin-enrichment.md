# Story 29.4: enrich feed item events with origin (check + world + sender)

Status: review

## Story

As a streamer (and as anyone reading the live event feed),
I want each item notification to show **where the item came from** - the origin **check**, the origin
**world/game**, and the **player** who sent it - in addition to the item itself,
so that "Master Sword" reads as "Master Sword - Bowser - Mario 64 (Michel_M)" and the audience
understands the cross-world flow at a glance.

Concrete target (to adapt to the real wording during build): when Michel (slot on *Mario 64*) checks
*Bowser* and that location holds Pierre's *Master Sword*, Pierre's overlay shows a toast:

```
Master Sword
Bowser - Mario 64 (Michel_M)
```

## Context

Today the bridge flattens every PrintJSON into a single `text` string in `_build_feed_event`
(`bridge/core/ap_client.py:195`): `{ type, text, color, timestamp }`. For an `ItemSend`/`ItemCheat`
the type is `item_sent` and the origin lives only as prose inside `text`
("Marie found Mothwing Cloak for Jean"). The overlay widgets (29.2) and the existing `EventFeed`
(story 9.13) therefore render origin only as whatever the server put in `text`, with no structured
fields to restyle, filter, or localize.

The data is **already available bridge-side** at the exact moment the event is built. `_track_item_send`
(`ap_client.py:816`) already extracts, from the structured `NetworkItem` on the packet:
`sender` (finder slot), `loc_id` (origin check), `item_id`, `receiver` (`receiving`). And the store
already resolves names: `resolve_player(slot)`, `_slot_games[slot]` (the world), `resolve_location(loc,
sender)` (the origin check name), `resolve_item(item, receiver)`. So the enrichment is **assembly of
data the bridge already holds** - no new AP packet handling.

This story is **new backend event plumbing**, which Epic 29 explicitly deferred ("the feed needs no new
backend event plumbing"). It therefore amends Epic 29 with a fourth story rather than touching 29.2.

### Blocking pre-condition (must be resolved first - see Task 1)

**Confirmed empirically (2026-06-16):** on a live session the operator sees item notifications on the
**progression page** but the **notifications and log overlays receive nothing**. Root cause: the
progression page derives its toasts from the **`reachable`** topic diff (per-slot `items_received`
counts, pushed via `reachable-push`), while the overlay `notifications`/`log` widgets subscribe to the
**`feed`** topic - which has **no live publisher**. The `goals` overlay uses `players` (pushed) and
should work. The "Tester en direct" button publishes to `overlay-test` (a separate channel) so it is not
evidence the feed works. **This makes the feed transport a prerequisite for story 29.2 to function at
all (notifications + log), not merely for this story's enrichment.**

Investigation confirms the **live feed -> Mercure delivery path is not wired in the current code**:

- Story 9.12's original design had the bridge fetch a `publisher-token` and POST feed events straight
  to Mercure `runs/{id}/feed` via an `aiohttp` `MercurePublisher`. **That code no longer exists in the
  bridge** (no `MercurePublisher`, no `publisher-token` use, no aiohttp Mercure POST).
- The current bridge only does `_broadcast("feed", ...)` to **local WS clients** (`ws_server.py`) plus a
  polling `GET /feed`. State/hints/reachable moved to an HTTP push model
  (`players-push`/`hints-push`/`reachable-push` -> Symfony publishes to Mercure), but **there is no
  `feed-push`** on either side.
- `PublisherTokenController` still exists in `api/` but **nothing calls it**; the orchestrateur only
  sets `INTERNAL_TOKEN` on the bridge container and does not consume its WS.
- The only current publisher on `runs/{id}/feed` is `OverlayTestController` (test events).

So real bridge feed events likely **never reach Mercure today**. Enriching an event that does not get
delivered is pointless, so Task 1 confirms (and, if confirmed broken, repairs) the live delivery path
before any enrichment lands. The chosen repair should mirror the existing model: a bridge `_push_feed_to_api`
-> a Symfony `FeedPushController` publishing to `runs/{id}/feed` (consistent with players/hints/reachable),
**or** restore the direct `publisher-token` + Mercure POST path. Decision recorded in Dev Agent Record.

### Naming inconsistency to settle (Task 1)

The bridge emits type `item_sent`; the frontend (`EventFeed`, overlay widgets, `OverlayTestController`)
keys on `item-received`. Nothing renames it across bridge/api/orchestrateur/frontend. Pick one canonical
value and make all layers agree (recommended: keep the bridge's `item_sent` as the wire value and adapt
the frontend constants + `OverlayTestController`, or normalize in the relay - decide during Task 1 with
the live-delivery fix so both are settled in one pass).

## Acceptance Criteria

1. **Structured origin on the feed event (bridge).** For `item_sent` events, `_build_feed_event` (or a
   dedicated builder reusing the `_track_item_send` extraction) attaches structured fields alongside the
   existing `text`/`type`/`color`/`timestamp`:
   - `item`: `{ id, name }` (name resolved in the **receiver's** game data package)
   - `location`: `{ id, name }` - the **origin check** (name resolved in the **sender's** game)
   - `sender`: `{ slot, name, game }` - finder + world
   - `receiver`: `{ slot, name, game }`
   Field names finalized during build; documented in `BRIDGE_API.md`. Non-item events are unchanged.
2. **Backward compatible.** `text` is still populated (existing `EventFeed` keeps working). Structured
   fields are **optional/additive**; a consumer that ignores them sees no regression. Events where origin
   cannot be resolved (unknown slot/game, `ItemCheat` edge shapes) still emit with `text` and omit the
   missing sub-fields rather than emitting `Item #123` / `Player N` placeholders where avoidable.
3. **Live delivery confirmed/repaired (Task 1).** Real `item_sent` events produced by the bridge reach
   the Mercure topic `runs/{id}/feed` end to end (not only `OverlayTestController` test events). The
   `item_sent` vs `item-received` type value is unified across all layers.
4. **Frontend type + render (notifications).** `FeedEvent` (in `features/overlay/overlay-api.ts`) gains the
   optional structured fields with an `is*` guard tolerant of their absence (AC-TS3, AC-TS4). The
   notifications overlay renders the item as the primary line and the origin as a secondary line
   (`{location} - {senderGame} ({senderName})`, adapted to real wording), falling back to `text` when the
   structured fields are absent. `ItemToast` gains an optional subtitle prop (or a thin overlay-only wrapper)
   - reused component, no behavioral change for existing callers.
5. **Frontend render (log + EventFeed).** The `log` overlay and the existing `EventFeed` show the origin
   for item events (compact: item + origin check + world + sender), still degrading to `text` when absent.
6. **`OverlayTestController` parity.** The test event published by 29.3's "Tester en direct" includes the
   new structured fields (with sample origin data) so the live preview and OBS source exercise the real
   render path, not just `text`.
7. **Tests.** Bridge: extend `tests/test_feed.py` (and/or a focused test) to assert the structured origin
   on an `ItemSend` packet, name resolution (sender game for location, receiver game for item), and graceful
   omission on unresolved slots. api/: if a `FeedPushController` is added, a functional test for the
   internal-secret gate + Mercure publish. Frontend: type guard + render covered by the gates.
8. **Gates green (all touched layers).**
   - bridge: `ruff check .`, `pytest`, `mypy bridge/ --config-file bridge/pyproject.toml`
   - api/ (if touched): `phpstan`, `php-cs-fixer`, `phpunit`, `app:architecture:ddd`
   - frontend: `pnpm typecheck`, `pnpm lint` (0/0), `pnpm build`

## Tasks / Subtasks

- [x] Task 1 - Confirm & settle the feed transport (AC 3) - **do first, blocking.**
  - [x] Traced: `runs/{id}/feed` had **no live publisher** for real bridge events (only the
        `OverlayTestController` test channel). Confirmed empirically by the user (progression page works via
        `reachable`; notifications/log overlays got nothing).
  - [x] Chose the push-HTTP model: `_push_feed_to_api` in the bridge -> new `FeedPushController` in `api/`
        publishing to `runs/{id}/feed` (mirrors `PlayersPushController`; keeps the bridge Mercure-agnostic).
  - [x] Unified the type via an anti-corruption map in `FeedPushController` (`item_sent` -> `item-received`);
        bridge keeps its AP-native `item_sent`, frontend + `OverlayTestController` keep `item-received`.
- [x] Task 2 - Bridge enrichment (AC 1, 2, 7).
  - [x] `_build_item_origin` helper (mirrors `_track_item_send`'s NetworkItem fast path) attaches
        `item`/`location`/`sender`/`receiver` to `item_sent` events; keeps `text`; added `slot_game` accessor.
  - [x] Updated `BRIDGE_API.md` feed sample. Extended `tests/test_feed.py` (origin present / omitted / non-item).
- [x] Task 3 - Frontend types + notifications render (AC 4).
  - [x] Extended `FeedEvent` (optional fields) + `feedItemOrigin` helper in `overlay-api.ts`. Added optional
        `subtitle` to `ItemToast`; `notifications-overlay.tsx` shows the origin, falls back to `text`.
- [x] Task 4 - Log + EventFeed render (AC 5).
  - [x] Origin suffix in `log-overlay.tsx` (reuses `feedItemOrigin`) and `event-feed.tsx` (local `itemOrigin`),
        both fall back to `text`. Demo rows enriched.
- [x] Task 5 - Test endpoint parity + gates (AC 6, 8).
  - [x] `OverlayTestController` sample event carries structured origin. Bridge + api + frontend gates green.

## Dev Notes

### Data sources (bridge, already present)
- `DataPackageStore.resolve_player(slot)` -> display name; `_slot_games[slot]` -> world/game.
- `resolve_location(loc_id, sender_slot)` -> origin check name (sender's game).
- `resolve_item(item_id, receiver_slot)` -> item name (receiver's game).
- `_track_item_send` already computes `sender`/`loc_id`/`item_id`/`receiver` from the structured
  `NetworkItem` (`item.player`/`item.location`/`item.item`) + top-level `receiving`. Reuse that logic.

### Boundaries / standards
- Bridge: keep handlers synchronous & pure where they are; resolution uses the store, no new AP calls.
- api/ (if `FeedPushController` added): Presentation only deserialize -> publish; gate by `X-Internal-Secret`
  exactly like `PlayersPushController` (AC-P3/P4). No new context (stays in `Sessions`).
- frontend: API-boundary data is `unknown` until an `is*` guard validates it (AC-TS3); no `as`. Origin
  fields optional so the guard must not reject legacy/non-item events.

### Out of scope (separate follow-up story)
- Per-slot "only my notifications" filtering of the notifications overlay (`?slot=`). This story makes it
  *trivial* (sender/receiver become structured), but the filter UI/logic is tracked separately.

## References
- `bridge/core/ap_client.py` - `_build_feed_event` (195), `_track_item_send` (816), `DataPackageStore` (105).
- `bridge/BRIDGE_API.md` - FeedEventType (140), feed WS event (810).
- `api/src/Sessions/Presentation/PlayersPushController.php` - push->Mercure pattern to mirror.
- `api/src/Sessions/Presentation/PublisherTokenController.php` - the (currently uncalled) publisher path.
- `api/src/Streaming/Presentation/OverlayTestController.php:102` - test event shape (`item-received`).
- `frontend/src/features/overlay/overlay-api.ts` - `FeedEvent` type + guard.
- `frontend/src/features/overlay/notifications-overlay.tsx`, `log-overlay.tsx`; `frontend/src/features/events/event-feed.tsx`.
- `frontend/src/features/reachability/item-toast.tsx` - reused toast (add optional subtitle).
- Stories 9.12 (bridge observer / original Mercure publish), 9.13 (EventFeed), 29.2 (overlay widgets), 29.3 (links panel).

## Dev Agent Record

- **Feed transport (push-HTTP).** Confirmed `runs/{id}/feed` had no live publisher. Added bridge
  `_emit_feed` (record + WS broadcast + push) and `_push_feed_to_api` -> Symfony `FeedPushController`
  (`POST /api/v1/internal/sessions/{id}/feed-push`, `X-Internal-Secret` gated) publishing to
  `runs/{id}/feed`. Mirrors players/hints/reachable; bridge stays Mercure-agnostic.
- **Type unification.** Anti-corruption map lives in `FeedPushController` (`item_sent` -> `item-received`)
  - single point at the bridge->app boundary. Bridge keeps `item_sent` (BRIDGE_API.md + tests unchanged
    for the value); frontend + `OverlayTestController` keep `item-received`.
- **Field shape.** `item` / `location`: `{ id, name }`; `sender` / `receiver`: `{ slot, name, game }`.
  Item name resolves in the receiver's game; the origin check (location) in the sender's game. Attached
  only when the packet carries a structured `NetworkItem` and finder/location resolve - else omitted,
  `text` preserved (no `Item #123` placeholders).
- **Files.** bridge: `core/ap_client.py` (`slot_game`, `_build_feed_event` + `_build_item_origin`,
  `_emit_feed`, `_push_feed_to_api`), `BRIDGE_API.md`, `tests/test_feed.py`. api:
  `Sessions/Presentation/FeedPushController.php` (new), `tests/Functional/FeedPushTest.php` (new),
  `Streaming/Presentation/OverlayTestController.php` (sample origin). frontend:
  `features/overlay/overlay-api.ts` (`FeedEvent` + `feedItemOrigin`), `notifications-overlay.tsx`,
  `log-overlay.tsx`, `reachability/item-toast.tsx` (`subtitle`), `events/event-feed.tsx`.

### Quality gates (all green)
- bridge: `ruff check` clean, `pytest` 173 passed, `mypy` no issues.
- api: `php-cs-fixer` 0, `phpstan` 0, `app:architecture:ddd` ok, `FeedPushTest` 3/3 (full suite re-run).
- frontend: `typecheck` 0, `lint` 0/0, `build` clean.

## Change Log
- 2026-06-16 - Implemented (status: review). push-HTTP feed transport (bridge `_push_feed_to_api` ->
  `FeedPushController` -> `runs/{id}/feed`) unblocks the notifications + log overlays; `item_sent` events
  enriched with structured origin (`item`/`location`/`sender`/`receiver`); type unified via relay
  anti-corruption map; notifications/log/EventFeed render "item - check - world (sender)" with `text`
  fallback; overlay-test sample carries origin. All gates green across bridge + api + frontend.
- 2026-06-16 - Confirmed empirically: live notifications work on the progression page (via the
  `reachable` topic diff) but the notifications + log overlays receive nothing (they subscribe to the
  `feed` topic, which has no live publisher). Reframes Task 1 as a prerequisite for 29.2 to function, not
  only for this story's enrichment. `goals` (players topic) should already work.
- 2026-06-16 - Story drafted (status: draft). Enrich `item_sent` feed events with structured origin
  (check + world + sender/receiver) reusing data the bridge already resolves; render in notifications +
  log + EventFeed with `text` fallback. Surfaced a blocking pre-condition: the live feed->Mercure path
  appears unwired today (no feed-push, no Mercure publisher in the current bridge, publisher-token
  uncalled) and the `item_sent`/`item-received` type mismatch - both settled in Task 1. Per-slot
  notification filtering split into a separate follow-up story.