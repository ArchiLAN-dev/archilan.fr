# Story 20.1: Ruff + Mypy as Bridge Quality Gates

## Story

**As a** developer,
**I want** `ruff check` and `mypy` to run as mandatory CI quality gates on the bridge,
**So that** Python style violations and type errors are caught before merge, mirroring PHPStan + CS Fixer on the API.

## Status

todo

## Acceptance Criteria

**AC1:** `ruff` and `mypy` are added to `bridge/requirements.txt` pinned to a minimum version. The Python target version is aligned to the actual bridge runtime: **Python 3.10**. Both `[tool.ruff]` `target-version` and `[tool.mypy]` `python_version` are set to `"py310"` / `"3.10"` respectively. The existing `target-version = "py311"` in `pyproject.toml` is corrected.

**AC2:** `ruff check bridge/` exits 0. All pre-existing violations are resolved:
- `E402` / `PLC0415` — import-not-at-top caused by the `sys.path` bootstrap: suppressed with `# noqa: E402  # temporary — removed in story 20.3` on each affected import.
- `PLW0603` — global statement in `rest.py`: suppressed with `# noqa: PLW0603  # temporary — removed in story 20.2` on each affected statement.
- All remaining violations are resolved without suppression.

**⚠ Suppressions added in this story are strictly temporary and must all be removed before Epic 20 is complete. Story 20.2 removes PLW0603. Story 20.3 removes E402. No `# noqa` from this story survives past story 20.3.**

**AC3:** A `[tool.mypy]` section is added to `bridge/pyproject.toml`:
```toml
[tool.mypy]
python_version = "3.10"
disallow_untyped_defs = true
ignore_missing_imports = true
mypy_path = "bridge/core"  # stopgap until story 20.3 fixes package structure
```
`mypy bridge/` exits 0. All public function and method signatures carry parameter and return type annotations.

**AC4:** Both `ruff check bridge/` and `mypy bridge/` are added as steps in the CI bridge job (`.github/workflows/backend.yml` or a dedicated `bridge` job), running before `pytest`.

**AC5:** The full existing bridge test suite passes unchanged (do not hardcode a specific count — new tests may be added before this story is implemented).

## Tasks / Subtasks

- [ ] Task 1: Create story file (this file)
- [ ] Task 2: Fix version mismatch — set ruff `target-version = "py310"` in `[tool.ruff]`
- [ ] Task 3: Add ruff and mypy to `bridge/requirements.txt`
- [ ] Task 4: Add `[tool.mypy]` section to `bridge/pyproject.toml`
- [ ] Task 5: Fix all ruff violations
  - [ ] 5a: Add `# noqa: E402  # temporary — removed in story 20.3` to imports in `bridge.py` after sys.path bootstrap
  - [ ] 5b: Add `# noqa: PLW0603  # temporary — removed in story 20.2` to global statements in `rest.py`
  - [ ] 5c: Resolve all remaining violations without suppression
- [ ] Task 6: Add type annotations to all public functions
  - [ ] 6a: `bridge/bridge.py` — `_main() -> None`
  - [ ] 6b: `bridge/core/config.py` — verify `from_env()` already typed
  - [ ] 6c: `bridge/core/rest.py` — all coroutines and helper functions
  - [ ] 6d: `bridge/core/state.py` — all `StateManager` methods
  - [ ] 6e: `bridge/core/ap_client.py` — all `ArchipelagoClient` methods
  - [ ] 6f: `bridge/core/loops.py`, `core/mercure.py`, `core/reachable.py`, `core/save_parser.py`, `core/wake_on_connect.py`
- [ ] Task 7: Add ruff + mypy steps to CI
- [ ] Task 8: Run full test suite — verify no regressions

## Dev Notes

### Version alignment

The bridge runtime uses Python 3.10 (confirmed via `requirements.txt` and the `python3.10` reference — there is no bridge Dockerfile). Ruff's `target-version = "py311"` would permit syntax valid only in 3.11+ (e.g. `ExceptionGroup`, `tomllib` in stdlib). Align to `py310` so ruff catches any such accidental usage. Mypy must match for consistent type-checking (3.10 introduced `X | Y` union syntax in annotations — this is the minimum we rely on).

### ignore_missing_imports scope

`ignore_missing_imports = true` is a **stopgap for untyped third-party libraries** (`aiohttp`, `boto3`, `websockets`). It does **not** excuse missing internal imports — if a bridge module fails to import a sibling, mypy will still report an `Cannot find implementation or library stub` error for the unresolved name, even with this setting. The flag suppresses only "no stub file found for library X" messages. Verify this by running `mypy bridge/` after implementation: internal `ModuleNotFoundError`s appear as `error: Cannot find implementation or library stub for module named "bridge.core.X"` and are not suppressed.

### Temporary noqa discipline

Every suppression must follow the exact format:
```python
# noqa: RULE_CODE  # temporary — removed in story 20.X
```
This makes tracking and removal unambiguous. The implementation agent for stories 20.2 and 20.3 must search for `# temporary` comments and remove them as part of their definition of done.

### mypy_path stopgap

Until Story 20.3 converts sibling imports to relative imports, mypy cannot resolve `from config import Config` (it sees it as a stdlib lookup). Setting `mypy_path = "bridge/core"` tells mypy to also search `bridge/core/` as a root, making the sibling imports resolve. This setting is removed in Story 20.3.

## File List

- `bridge/requirements.txt` — add `ruff>=0.4` and `mypy>=1.10`
- `bridge/pyproject.toml` — fix `target-version` to `py310`; add `[tool.mypy]` section
- `bridge/bridge.py` — type annotations; `# noqa` suppressions on post-bootstrap imports
- `bridge/core/rest.py` — type annotations; `# noqa: PLW0603` suppressions on global statements
- `bridge/core/ap_client.py` — type annotations
- `bridge/core/state.py` — type annotations
- `bridge/core/loops.py` — type annotations
- `bridge/core/mercure.py` — type annotations
- `bridge/core/reachable.py` — type annotations
- `bridge/core/save_parser.py` — type annotations
- `bridge/core/wake_on_connect.py` — type annotations
- `.github/workflows/backend.yml` — add ruff + mypy steps to bridge job
- `_bmad-output/implementation-artifacts/20-1-bridge-ruff-mypy-quality-gates.md` — this file

## Change Log

| Date       | Change                                                                 |
|------------|------------------------------------------------------------------------|
| 2026-05-15 | Story created                                                          |
| 2026-05-15 | Revised: align py310, mark noqa as strictly temporary, remove hardcoded test count |
