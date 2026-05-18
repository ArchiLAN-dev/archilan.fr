# Story 20.2: Eliminate Module-Level Mutable State from rest.py

## Story

**As a** developer,
**I want** runtime pause/wake coordination state to live in an explicit object rather than module globals,
**So that** bridge modules have no hidden shared state and tests can instantiate multiple app instances in isolation.

## Status

review

## Acceptance Criteria

**AC1:** A `PauseResumeCoordinator` dataclass is created in `bridge/core/coordinator.py`:
```python
import asyncio
from dataclasses import dataclass

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
When `coordinator` is `None`, a default `PauseResumeCoordinator()` is instantiated internally. The coordinator is stored using a plain string key `app["coordinator"]` for now - typed `AppKey` storage is introduced in Story 20.4.

**AC3:** `_pause_flow` and `_cancel_wake_task` receive the coordinator as an explicit parameter. No `global` statement remains anywhere in `rest.py`. The module-level variable declarations (`_wake_stop_event`, `_wake_task`) are removed entirely.

**AC4:** All existing callers of `create_app()` continue to work without signature changes:
- `create_app(state, ap_client)` - positional, no semaphore, no coordinator ✓
- `create_app(state, ap_client, reachable_semaphore)` - positional semaphore ✓
- `create_app(state, ap_client, coordinator=PauseResumeCoordinator())` - explicit coordinator ✓

`bridge/tests/test_wake_on_connect.py` currently accesses the removed module globals directly (`_rest._wake_stop_event`, `_rest._wake_task`). These test lines **must** be updated as part of this story to read the coordinator from the app instead:
```python
# before (module global access)
assert _rest._wake_task is not None

# after (coordinator via test app instance)
coordinator = app["coordinator"]
assert coordinator.wake_task is not None
```
All other test files that do not access these globals pass without modification.

**AC5:** The `# noqa: PLW0603` suppressions placed in Story 20.1 are removed - they are no longer needed once the global statements are gone. `ruff check bridge/` exits 0 with no PLW0603 violations. `mypy bridge/` exits 0. The full existing test suite passes.

## Tasks / Subtasks

- [x] Task 1: Create story file (this file)
- [x] Task 2: Create `bridge/core/coordinator.py` with `PauseResumeCoordinator` dataclass
- [x] Task 3: Update `create_app` signature
  - [x] 3a: Add keyword-only `coordinator` parameter after `*`
  - [x] 3b: Default to `PauseResumeCoordinator()` internally when `None`
  - [x] 3c: Store coordinator on `app["coordinator"]` (plain string key - `AppKey` deferred to 20.4)
- [x] Task 4: Update `_pause_flow`
  - [x] 4a: Add `coordinator: PauseResumeCoordinator` parameter
  - [x] 4b: Replace `global _wake_stop_event, _wake_task` + mutation with `coordinator.wake_stop_event = ...` etc.
  - [x] 4c: Pass `request.app["coordinator"]` from the `post_pause` closure
- [x] Task 5: Update `_cancel_wake_task`
  - [x] 5a: Add `coordinator: PauseResumeCoordinator` parameter
  - [x] 5b: Replace all global reads/writes with coordinator attribute access
  - [x] 5c: Pass coordinator from `post_resume` closure
- [x] Task 6: Remove module-level variable declarations `_wake_stop_event` and `_wake_task`
- [x] Task 7: Remove `# noqa: PLW0603` suppressions added in Story 20.1
- [x] Task 8: Update `bridge/tests/test_wake_on_connect.py` - replace `_rest._wake_stop_event` / `_rest._wake_task` accesses with coordinator reads from the test app; verify full test suite passes
- [x] Task 9: Verify quality gates - ruff (0 PLW0603), mypy (0), full test suite green

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
The `# type: ignore` is accepted here and is **Story 20.4's explicit responsibility to remove** - 20.4 must migrate the `app["coordinator"]` string key to an `AppKey[PauseResumeCoordinator]` and delete the `# type: ignore`.

### Unit tests that call _pause_flow / _cancel_wake_task directly

Tests that invoke `_pause_flow` or `_cancel_wake_task` directly (not via an HTTP request) cannot retrieve the coordinator from `request.app`. Those tests should construct the coordinator directly:
```python
coordinator = PauseResumeCoordinator()
await _pause_flow(ap_client, coordinator)
assert coordinator.wake_task is not None
```
Only tests that fire actual HTTP requests to `/pause` or `/resume` should retrieve the coordinator via `app["coordinator"]`.

### Dataclass vs simple class

The `PauseResumeCoordinator` is intentionally a bare data container. No methods. If orchestration logic grows in the future, it can be promoted to a class then - but right now YAGNI applies.

### PLW0603 removal checkpoint

Before completing this story, grep `rest.py` for `# temporary - removed in story 20.2` (placed in Story 20.1) and confirm all such lines are removed.

## File List

- `bridge/core/coordinator.py` - new file: `PauseResumeCoordinator` dataclass
- `bridge/core/rest.py` - updated: `create_app` signature (keyword-only coordinator), `_pause_flow`, `_cancel_wake_task`, `_resume_flow`; module-level globals removed; PLW0603 noqa comments removed
- `bridge/tests/test_wake_on_connect.py` - updated: coordinator imported, module-global reads replaced by coordinator attribute reads, direct function calls updated
- `bridge/tests/test_pause_endpoint.py` - updated: coordinator imported, direct `_pause_flow` calls pass `PauseResumeCoordinator()`
- `bridge/tests/test_resume_endpoint.py` - updated: coordinator imported, direct `_resume_flow` calls pass `coordinator=PauseResumeCoordinator()`
- `_bmad-output/implementation-artifacts/20-2-bridge-eliminate-module-globals.md` - this file

## Dev Agent Record

### Completion Notes

Implemented by claude-sonnet-4-6 on 2026-05-15.

- Created `bridge/core/coordinator.py` - `PauseResumeCoordinator` dataclass with `wake_stop_event` and `wake_task` fields.
- Removed the two module-level globals `_wake_stop_event` / `_wake_task` from `rest.py`.
- `_pause_flow`, `_cancel_wake_task` now receive `coordinator: PauseResumeCoordinator` explicitly; `_resume_flow` also receives it and passes it to `_cancel_wake_task`.
- `create_app` gains keyword-only `coordinator` parameter (defaults to `PauseResumeCoordinator()`); stored on `app["coordinator"]` with plain string key (AppKey migration deferred to 20.4).
- `post_pause` and `post_resume` closures retrieve coordinator via `request.app["coordinator"]` with `# type: ignore[assignment]` as specified by AC2.
- Updated three test files - all direct calls to `_pause_flow` / `_resume_flow` now pass a `PauseResumeCoordinator()` instance; module-global reads in the integration test replaced by coordinator attribute reads.
- Quality gates: `ruff check . --select PLW0603` → 0 violations; `ruff check .` → All checks passed; `mypy .` → Success (2 files); `pytest tests/` → 141/141 passed.
- The `NotAppKeyWarning` from aiohttp is expected; 20.4's responsibility to resolve via `web.AppKey`.

## Change Log

| Date       | Change                                                                            |
|------------|-----------------------------------------------------------------------------------|
| 2026-05-15 | Story created                                                                     |
| 2026-05-15 | Revised: keyword-only signature specified; AppKey deferred to 20.4; noqa removal checkpoint added |
| 2026-05-15 | Implemented: coordinator.py created, rest.py refactored, 3 test files updated; all gates green |
