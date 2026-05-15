# Story 20.2: Eliminate Module-Level Mutable State from rest.py

## Story

**As a** developer,
**I want** runtime pause/wake coordination state to live in an explicit object rather than module globals,
**So that** bridge modules have no hidden shared state and tests can instantiate multiple app instances in isolation.

## Status

todo

## Acceptance Criteria

**AC1:** A `PauseResumeCoordinator` dataclass is created in `bridge/core/coordinator.py`:
```python
import asyncio
from dataclasses import dataclass, field

@dataclass
class PauseResumeCoordinator:
    wake_stop_event: asyncio.Event | None = None
    wake_task: "asyncio.Task[None] | None" = None
```

**AC2:** `create_app` in `rest.py` is updated with an explicit keyword-only `coordinator` parameter. The full signature is:
```python
def create_app(
    state: StateManager,
    ap_client: ArchipelagoClient,
    reachable_semaphore: asyncio.Semaphore | None = None,
    *,
    coordinator: PauseResumeCoordinator | None = None,
) -> web.Application:
```
When `coordinator` is `None`, a default `PauseResumeCoordinator()` is instantiated internally. The coordinator is stored using a plain string key `app["coordinator"]` for now — typed `AppKey` storage is introduced in Story 20.4.

**AC3:** `_pause_flow` and `_cancel_wake_task` receive the coordinator as an explicit parameter. No `global` statement remains anywhere in `rest.py`. The module-level variable declarations (`_wake_stop_event`, `_wake_task`) are removed entirely.

**AC4:** All existing callers of `create_app()` continue to work without signature changes:
- `create_app(state, ap_client)` — positional, no semaphore, no coordinator ✓
- `create_app(state, ap_client, reachable_semaphore)` — positional semaphore ✓
- `create_app(state, ap_client, coordinator=PauseResumeCoordinator())` — explicit coordinator ✓

`bridge/tests/test_wake_on_connect.py` currently accesses the removed module globals directly (`_rest._wake_stop_event`, `_rest._wake_task`). These test lines **must** be updated as part of this story to read the coordinator from the app instead:
```python
# before (module global access)
assert _rest._wake_task is not None

# after (coordinator via test app instance)
coordinator = app["coordinator"]
assert coordinator.wake_task is not None
```
All other test files that do not access these globals pass without modification.

**AC5:** The `# noqa: PLW0603` suppressions placed in Story 20.1 are removed — they are no longer needed once the global statements are gone. `ruff check bridge/` exits 0 with no PLW0603 violations. `mypy bridge/` exits 0. The full existing test suite passes.

## Tasks / Subtasks

- [ ] Task 1: Create story file (this file)
- [ ] Task 2: Create `bridge/core/coordinator.py` with `PauseResumeCoordinator` dataclass
- [ ] Task 3: Update `create_app` signature
  - [ ] 3a: Add keyword-only `coordinator` parameter after `*`
  - [ ] 3b: Default to `PauseResumeCoordinator()` internally when `None`
  - [ ] 3c: Store coordinator on `app["coordinator"]` (plain string key — `AppKey` deferred to 20.4)
- [ ] Task 4: Update `_pause_flow`
  - [ ] 4a: Add `coordinator: PauseResumeCoordinator` parameter
  - [ ] 4b: Replace `global _wake_stop_event, _wake_task` + mutation with `coordinator.wake_stop_event = ...` etc.
  - [ ] 4c: Pass `request.app["coordinator"]` from the `post_pause` closure
- [ ] Task 5: Update `_cancel_wake_task`
  - [ ] 5a: Add `coordinator: PauseResumeCoordinator` parameter
  - [ ] 5b: Replace all global reads/writes with coordinator attribute access
  - [ ] 5c: Pass coordinator from `post_resume` closure
- [ ] Task 6: Remove module-level variable declarations `_wake_stop_event` and `_wake_task`
- [ ] Task 7: Remove `# noqa: PLW0603` suppressions added in Story 20.1
- [ ] Task 8: Verify all existing tests pass without modification
- [ ] Task 9: Verify quality gates — ruff (0 PLW0603), mypy (0), full test suite green

## Dev Notes

### Keyword-only to protect existing positional callers

The current `create_app` signature is:
```python
def create_app(state, ap_client, reachable_semaphore=None) -> web.Application:
```
Callers like `create_app(state, ap_client, semaphore)` use positional arguments. Adding `coordinator` as a regular parameter after `reachable_semaphore` would be safe, but making it keyword-only (after `*`) is explicit and prevents accidental positional passing, which is the correct design for a new optional infrastructure dependency.

### AppKey deferred to Story 20.4

Story 20.4 will introduce `web.AppKey` typed storage for all app dependencies. In this story, use a plain string `"coordinator"` as the key. When retrieving in handlers:
```python
coordinator: PauseResumeCoordinator = request.app["coordinator"]  # type: ignore[assignment]
```
The `# type: ignore` is accepted here — Story 20.4 removes it when AppKey is introduced.

### Dataclass vs simple class

The `PauseResumeCoordinator` is intentionally a bare data container. No methods. If orchestration logic grows in the future, it can be promoted to a class then — but right now YAGNI applies.

### PLW0603 removal checkpoint

Before completing this story, grep `rest.py` for `# temporary — removed in story 20.2` (placed in Story 20.1) and confirm all such lines are removed.

## File List

- `bridge/core/coordinator.py` — new file: `PauseResumeCoordinator` dataclass
- `bridge/core/rest.py` — updated: `create_app` signature (keyword-only coordinator), `_pause_flow`, `_cancel_wake_task`; module-level globals removed; PLW0603 noqa comments removed
- `bridge/tests/test_wake_on_connect.py` — updated: replace `_rest._wake_stop_event` / `_rest._wake_task` accesses with coordinator reads from the app
- `_bmad-output/implementation-artifacts/20-2-bridge-eliminate-module-globals.md` — this file

## Change Log

| Date       | Change                                                                            |
|------------|-----------------------------------------------------------------------------------|
| 2026-05-15 | Story created                                                                     |
| 2026-05-15 | Revised: keyword-only signature specified; AppKey deferred to 20.4; noqa removal checkpoint added |
