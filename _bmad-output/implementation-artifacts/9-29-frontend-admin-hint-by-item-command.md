# Story 9.29: Frontend - Admin Hint-by-Item Uses the Admin Command

Status: review

## Story

As an admin requesting a hint for an item a player has not yet received,
I want the site to issue the AP **admin** hint command for that item and player,
So that the hint is created for the right slot (any slot), not silently resolved against the bridge's connected slot.

## Context

The "Items non reçus" panel's hint button calls `handleHintItem(itemName)`, which builds the chat command **client-side** and relays it through `POST /admin/sessions/{id}/commands`:

```js
const command = hintFree ? `!hint ${itemName}` : `!hint${itemName}`;
```

`!hint`/`/hint` are **self-hints** — AP resolves them against the *connected* client's slot (the bridge), so hinting an item that belongs to another slot fails or hints the wrong world (observed: `Bridge: !hint Armory Key`). The comment calling `!hint` an "admin command" is wrong.

The validated command (live test) is `!admin /hint <player> <item>`. The page already knows the viewed slot's player (`state.data.player`), who is the owner of the items in the "Items non reçus" list.

**Free vs paid:** the bridge acts as admin and cannot spend a player's hint points — `!admin /hint` creates a free/priority hint. A truly *paid* hint (charged to the player) requires the bridge to connect **as the paying slot** and send `/hint` on its behalf — tracked separately as story 9.30 (bridge). This story fixes only the admin/free path.

## Acceptance Criteria

1. **Given** the hint mode is "Gratuit (admin)" (`hintFree = true`) **When** the user hints an item from the "Items non reçus" panel **Then** the site sends `!admin /hint <state.data.player> <itemName>` (not `!hint <itemName>`).

2. **Given** the slot data is not loaded (`state.kind !== "data"`) **When** the handler runs **Then** it returns without sending a command.

3. **Given** the hint mode is "Payant" (`hintFree = false`) **Then** behavior is unchanged in this story (still `!hint<itemName>`), pending the bridge connect-as-slot flow (9.30).

4. **Given** the same panel on the personal-run slot page **Then** the same admin-command fix applies (`personal-run-slot-detail-page.tsx`).

## Tasks / Subtasks

- [x] Task 1 - Fix `handleHintItem` on both slot pages (AC: #1, #2, #4)
  - [x] 1.1 `admin-slot-reachability-page.tsx`: guard `state.kind === "data"`, free → `!admin /hint ${state.data.player} ${itemName}`
  - [x] 1.2 `personal-run-slot-detail-page.tsx`: same
- [x] Task 2 - Quality gates
  - [x] 2.1 `pnpm typecheck` → 0 errors
  - [x] 2.2 `pnpm lint` → 0 errors/warnings
  - [x] 2.3 `pnpm build` → clean

## Dev Notes

### Files to modify

- `frontend/src/features/admin/admin-slot-reachability-page.tsx` - `handleHintItem` (~line 397)
- `frontend/src/features/personal-runs/personal-run-slot-detail-page.tsx` - `handleHintItem` (~line 545)

The location-based hint button (`handleHintLocation` → `POST /sessions/{id}/slots/{slot}/hints/request`) is already correct via the bridge (story 9.28) and is out of scope here.

### References

- [Source: frontend/src/features/admin/admin-slot-reachability-page.tsx:397] - `handleHintItem`
- [Source: frontend/src/features/admin/admin-slot-reachability-page.tsx:907] - panel wiring (`onHintRequest={handleHintItem}`)
- [Source: frontend/src/features/personal-runs/personal-run-slot-detail-page.tsx:545] - `handleHintItem`
- [Source: ap-server log] - validated `!admin /hint masterkafey_LM Boolossus MiniBoo 1`

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Completion Notes List

### File List
