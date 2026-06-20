# Story 29.5: per-slot overlay filter ("my notifications only")

Status: review

## Story

As a streamer who owns one slot in a shared session,
I want to scope an overlay to **my slot** so the notifications and event log show only what concerns me,
not every player's items,
so that my OBS sources read as *my* run rather than the whole lobby.

## Context

29.4 made each `item_sent` feed event carry a structured `sender` and `receiver` (`{ slot, name, game }`).
That unblocks a purely **frontend** per-slot filter on the existing `?slot=` query param (already parsed
in `overlay-params`, already honored by the `goals` widget). A single `?slot=N` now means "this overlay
is for slot N" across all three widgets. The operator picks the slot from a dropdown in the links panel
(29.3); the chosen value is appended to every generated URL and the live preview.

Decisions (from the user):
- Filter applies to **notifications + log** (and `goals`, which already filtered). Notifications keep
  items **received by** the slot; the log keeps events that **involve** the slot as sender or receiver,
  and **hides global events** (chat/system/hint without a structured slot) while a slot is selected.
- Slot selection is a **dropdown** populated from the session's slots (`GET /sessions/{id}/players`).
- **Test events bypass the filter** (they carry `__test__`), so the panel's "Tester en direct" button
  always shows in the preview regardless of the selected slot.

## Acceptance Criteria

1. **Notifications filter.** With `?slot=` set, the notifications overlay shows only `item-received`
   events whose `receiver` matches one of the slots (by slot index or display name); no filter when
   absent. `?slot=` accepts **multiple comma-separated slots** (e.g. `1,3`) to combine players.
2. **Log filter.** With `?slot=` set, the log overlay shows only events involving one of the slots
   (sender or receiver); events without a matching structured actor (global chat/system) are dropped. No
   filter when absent. Multiple comma-separated slots supported.
3. **Tests honor the filter.** The "Tester en direct" event **targets the selected scope's slot**
   (single slot → that slot; group → its first member; all → none), and the widgets apply the slot
   filter to it like any real event (no `__test__` bypass). So a per-slot overlay reacts only to its own
   test, faithfully exercising the filter. `?demo=1` client-side samples remain unaffected.
4. **Per-scope link list (panel).** `overlay-links-panel` shows, for each widget, one copyable URL per
   scope: "Tous les joueurs" (global, no `?slot=`) then one per session slot (`?slot=<key>`). Slots are
   loaded via a typed `fetchOverlaySlots` (`/players`, typed result or `null`, no throw, `apiFetch`,
   `env.apiBaseUrl`). With no slots (session not running) only the global link shows, with a hint. This
   lets the operator run a global overlay AND one per player simultaneously (same token, different URLs).
5. **Preview.** The per-widget preview renders the global overlay (`?slot=` omitted); each listed URL is
   independently copyable.
5b. **Custom group.** A "Groupe personnalisé" checkbox set (one per player) builds a combined
   `?slot=a,b` link; when ≥2 players are ticked, a "Groupe (N)" copyable link is added to every widget's
   list. Selection is local UI state (`useState`).
6. **Reuse, pure helpers.** Matching logic lives in `overlay-api.ts` (`actorMatchesSlot`,
   `eventInvolvesSlot`) - pure, no I/O - consistent with the `goals` widget's existing slot match.
7. **Bridge observer excluded.** The dropdown lists **real players only** - the injected TextOnly
   "Bridge" observer slot (game "Archipelago") and any spectator/group slot are filtered out. The bridge
   `GET /state` payload is enriched with per-slot `game` + `slot_type` (it carried neither); the frontend
   drops slots whose `slot_type !== "player"` or whose `game` is empty/"Archipelago" (lenient when the
   fields are absent).
8. **Gates green.** bridge (`ruff`/`pytest`/`mypy`) + frontend (`typecheck`/`lint` 0/0/`build`).

## Tasks / Subtasks

- [x] Task 1 - Match helpers + types (AC 1, 2, 6).
  - [x] `actorMatchesSlot` / `eventInvolvesSlot` in `overlay-api.ts`; `__test__?` added to `FeedEvent`.
- [x] Task 2 - Widget filters (AC 1, 2, 3).
  - [x] `notifications-overlay` filters on `receiver`; `log-overlay` on sender/receiver; both bypass on
        `__test__`; `params.slot` added to the `onEvent` deps.
- [x] Task 3 - Panel per-scope link list (AC 4, 5).
  - [x] `fetchOverlaySlots` (typed, guarded) in `overlay-api.ts`; per widget the panel lists "Tous les
        joueurs" + one copyable URL per slot (`overlayUrl(widget, slotKey)`), each with its own copy
        feedback; preview stays on the global URL. (Initial single dropdown replaced after user feedback.)
- [x] Task 4 - Exclude the bridge observer (AC 7).
  - [x] Bridge: `store.slot_type()` accessor + `ArchipelagoClient.get_players_state()` (to_api_dict +
        per-slot `game`/`slot_type`); `GET /state` now returns it. `test_get_state_includes_game_and_type`.
  - [x] Frontend: `isRealPlayerSlot` filter in `fetchOverlaySlots` (drops Archipelago/empty-game and
        non-player types).
- [x] Task 5 - Gates (AC 8). bridge ruff/pytest(174)/mypy + frontend typecheck/lint/build all green.

## Dev Notes

- `?slot=` matches the bridge slot index (the `players` record key, = AP slot number) or the slot's
  display name - same rule as `goals`'s `matchesSlotFilter`. The dropdown sends the index key.
- `GET /sessions/{id}/players` proxies the bridge `/state` (`to_api_dict` → `{ slots: { key: {slot_name}}}`)
  and requires the session to be **running**; otherwise it 409s and `fetchOverlaySlots` returns `null`,
  so the panel degrades to "all slots".
- Notifications = items **received by** the slot (the streamer's own pickups). Log = events the slot is
  **involved in** (sender or receiver), matching "ce qui me concerne".

## References
- `frontend/src/features/overlay/overlay-api.ts` - `FeedEvent`, match helpers, `fetchOverlaySlots`.
- `frontend/src/features/overlay/notifications-overlay.tsx`, `log-overlay.tsx` - filters.
- `frontend/src/features/overlay/goals-overlay.tsx` - existing `matchesSlotFilter` (consistency).
- `frontend/src/features/overlay/overlay-links-panel.tsx` - slot dropdown + URL.
- `frontend/src/features/overlay/overlay-params.ts` - `slot` param.
- Story 29.4 - structured `sender`/`receiver` this filter relies on.

## Dev Agent Record

- **Mostly frontend; one small bridge addition.** The filter itself rides on 29.4's structured
  `receiver`/`sender` and the pre-existing `?slot=` param. The only backend change is enriching the
  bridge `GET /state` (which `/players` proxies) with per-slot `game` + `slot_type` so the dropdown can
  exclude the injected TextOnly "Bridge" observer (game "Archipelago") - `to_api_dict` carried neither.
- **One param, all widgets.** `?slot=N` scopes notifications (receiver), log (sender|receiver), and goals
  (already filtered) - so a single per-slot URL set gives a streamer a coherent "my run" overlay stack.
- **Test bypass via `__test__`.** Rather than special-casing the overlay-test topic in the stream hook
  (which merges topics), the filter ignores events carrying the `__test__` marker the test endpoint
  already sets - so the panel's "Test" button keeps working at any slot selection.
- **Bridge filter discriminator = game "Archipelago".** The observer connects with name "Bridge" / game
  "Archipelago" (TextOnly); the orchestrateur injects that slot into every multiworld. Filtering on the
  game (plus non-player `slot_type`) is robust, vs the configurable name. Only `GET /state` was enriched
  (not the `players` Mercure topic), so goals/PlayerProgressGrid are untouched.

### Quality gates (all green)
- bridge: `ruff` clean, `pytest` 174 passed, `mypy` no issues.
- frontend: `typecheck` 0, `lint` 0/0, `build` clean.

## Change Log
- 2026-06-16 - Story created and implemented (status: review). Per-slot `?slot=` filter for notifications
  (receiver) and log (sender|receiver), global events hidden while filtered, test events bypass; panel
  slot dropdown from `/players` injecting `?slot=` into URLs + preview. Pure match helpers in
  `overlay-api`.
- 2026-06-16 - Follow-up (user feedback): exclude the injected "Bridge" observer from the slot list.
  Enriched the bridge `GET /state` with per-slot `game` + `slot_type` (`store.slot_type()` +
  `get_players_state()`); frontend `isRealPlayerSlot` drops Archipelago/empty-game, non-player slots, and
  (deploy-independent fallback) any slot named "Bridge". No longer strictly frontend-only.
- 2026-06-16 - Follow-up (user feedback): replaced the single slot dropdown with a per-scope link list -
  each widget shows "Tous les joueurs" (global) + one copyable URL per player, so an operator can run a
  combined overlay AND one per streamer at the same time. Frontend gates green.
- 2026-06-16 - Follow-up (user feedback, verified in-browser): replaced the per-overlay "Tester en
  direct" buttons with a single **test form** - pick an event **type** (Objet reçu / Objectif / Location
  / Indice / Chat) and a **player**, then send. `OverlayTestController` now builds the sample **by type**
  (`{type, slot}` body) targeting that player; sending auto-switches to the overlay that renders the type
  so the preview reacts (with a 1.5s delay when the tab changes, to let the remounted preview reconnect
  before the one-shot event). Verified all types render in-browser.
- 2026-06-16 - Follow-up (user feedback, verified in-browser): the test now **honors the slot filter**
  instead of bypassing it. `testOverlayEvent` takes the selected slot; `OverlayTestController`
  targets the sample at it (receiver slot for notifications/log, slots key for goals); the `__test__`
  filter bypass was removed from all three widgets (goals keeps `__test__` only for baseline suppression,
  now also slot-filtered). api + frontend gates green; overlay tests 15/15.
- 2026-06-16 - Follow-up (user feedback): `?slot=` now accepts multiple comma-separated slots
  (`OverlayParams.slot: string|null` → `slots: string[]`; matchers `actorMatchesSlots`/`eventInvolvesSlots`;
  goals `matchesSlotFilter` over a list). Panel gains a "Groupe personnalisé" checkbox set that emits a
  combined "Groupe (N)" link per widget. Frontend gates green.
- 2026-06-16 - New widget + fix (user feedback): "Checks réalisables" overlay (`reachable-overlay.tsx`,
  route `/o/{session}/reachable`, panel "Checks" tab). It shows the **same list** as the progression
  page's "Checks faisables maintenant" (`reachable_unchecked` location names) for the scoped player -
  initial snapshot via the anonymous `GET /sessions/{id}/slots/{n}/reachable`, then live over the per-slot
  `runs/{id}/slots/{n}/reachable` SSE (granted as a `{slot}` URI template in the overlay subscribe JWT).
  It's per-slot, so it needs a `?slot=`. Corrected from an earlier wrong take (a reachable_now counter fed
  by the players topic, with a pointless test event - both removed). Frontend + api gates green;
  PublicOverlaySubscribeTest updated for the new template topic.
