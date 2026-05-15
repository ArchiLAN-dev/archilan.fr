# Story 20.3: Fix sys.path Import Hack in bridge.py

## Story

**As a** developer,
**I want** bridge modules to be importable via proper Python package paths,
**So that** mypy and ruff resolve symbols correctly and internal imports are distinguishable from stdlib imports.

## Status

todo

## Acceptance Criteria

**AC1:** All imports inside `bridge/core/*.py` use relative imports:
```python
# before
from config import Config
from state import StateManager
# after
from .config import Config
from .state import StateManager
```

**AC2:** `bridge/bridge.py` removes the `sys.path.insert` block entirely and imports via absolute package paths:
```python
from bridge.core.config import Config
from bridge.core.state import StateManager
# etc.
```

**AC3:** The `__all__` list in `bridge/bridge.py` is reduced to the public API surface only. The following private symbols are removed from `__all__` and updated in every test that imports them:

| Private symbol removed from `__all__` | Canonical import after this story |
|---|---|
| `_build_feed_event` | `from bridge.core.ap_client import _build_feed_event` |
| `_PRINT_TYPE_MAP` | `from bridge.core.ap_client import _PRINT_TYPE_MAP` |
| `_WS_RETRY_DELAYS` | `from bridge.core.ap_client import _WS_RETRY_DELAYS` |
| `_compute_reachable` | `from bridge.core.reachable import _compute_reachable` |
| `_daemon_ready_events` | `from bridge.core.reachable import _daemon_ready_events` |
| `_reachable_cache` | `from bridge.core.reachable import _reachable_cache` |
| `_reachable_daemons` | `from bridge.core.reachable import _reachable_daemons` |
| `_start_daemon` | `from bridge.core.reachable import _start_daemon` |

Each test file that imports these symbols is updated to use the canonical path.

**AC4:** The two supported entry points are verified by explicit CI steps:

```yaml
# CI step — verify script mode (Docker uses this)
- name: Bridge script entry point
  working-directory: .
  run: python bridge/bridge.py --help || true  # exits after argparse help, not 0 but not an import error

# CI step — verify module mode (tests use this)
- name: Bridge module entry point
  run: python -m bridge.bridge --help || true
```

If the bridge has no `--help` flag, a simpler smoke test is used:
```bash
python -c "import bridge.bridge; print('import ok')"
python bridge/bridge.py &  # start in background, wait 1s, kill, check no import error in stderr
```
The exact command is determined during implementation based on what the bridge exposes. The requirement is: **no `ImportError` or `ModuleNotFoundError` on either entry point**. This must be demonstrated in CI, not just locally.

**AC5:** All `# noqa: E402  # temporary — removed in story 20.3` comments placed in Story 20.1 are removed from `bridge.py`. The `mypy_path = "bridge/core"` stopgap from Story 20.1's mypy config is also removed.

**AC6:** `mypy bridge/` exits 0, `ruff check bridge/` exits 0, the full existing test suite passes.

## Tasks / Subtasks

- [ ] Task 1: Create story file (this file)
- [ ] Task 2: Convert `bridge/core/*.py` to relative imports
  - [ ] 2a: `core/ap_client.py`
  - [ ] 2b: `core/loops.py`
  - [ ] 2c: `core/mercure.py`
  - [ ] 2d: `core/reachable.py`
  - [ ] 2e: `core/rest.py`
  - [ ] 2f: `core/save_parser.py`
  - [ ] 2g: `core/state.py`
  - [ ] 2h: `core/wake_on_connect.py`
  - [ ] 2i: `core/domain.py` (verify — likely no sibling imports)
- [ ] Task 3: Update `bridge/bridge.py`
  - [ ] 3a: Remove `sys.path.insert` block; remove unused `os` / `sys` imports
  - [ ] 3b: Convert all `from config import X` → `from bridge.core.config import X`
  - [ ] 3c: Clean up `__all__` per the table in AC3
- [ ] Task 4: Update test imports
  - [ ] 4a: Grep tests for `from bridge.bridge import _` to find all private symbol imports
  - [ ] 4b: Redirect each to the canonical module per AC3 table
- [ ] Task 5: Remove stopgap artefacts from Story 20.1
  - [ ] 5a: Remove `# noqa: E402  # temporary — removed in story 20.3` comments from `bridge.py`
  - [ ] 5b: Remove `mypy_path = "bridge/core"` from `pyproject.toml`
- [ ] Task 6: Verify both entry points (script mode + module mode) produce no import errors
- [ ] Task 7: Add entry point smoke test to CI (per AC4)
- [ ] Task 8: Verify quality gates — ruff, mypy, full test suite

## Dev Notes

### Script mode and absolute imports

`python bridge/bridge.py` sets `__package__ = None` and `__name__ = "__main__"`. Relative imports like `from .core.config import Config` require `__package__` to be set — they fail in script mode.

The solution is **absolute imports in `bridge.py`** (`from bridge.core.config import Config`) combined with ensuring `bridge/`'s parent directory is on `sys.path`. In Docker, the `WORKDIR` is `/bridge` and the bridge root `/` is typically on `PYTHONPATH` or `sys.path`. Verify this is the case in the Dockerfile before removing the sys.path manipulation.

If the Docker WORKDIR is `/bridge` (the bridge package directory itself), then `from bridge.core.config import Config` would fail because `bridge` is not a parent-relative import — the CWD is already inside the package. In that case, set `WORKDIR /` and `CMD ["python", "bridge/bridge.py"]` in Docker, or switch to `python -m bridge.bridge`.

**The implementer must read the actual Dockerfile** to determine which approach applies before writing code. This is a prerequisite step.

### Test file inventory for private symbol imports

Before writing any code, grep the test directory:
```bash
grep -rn "from bridge.bridge import" bridge/tests/
grep -rn "from bridge import" bridge/tests/
```
For each hit, identify which private symbol is imported and replace the import with the canonical source per AC3's table.

### `__init__.py` files

`bridge/__init__.py` and `bridge/core/__init__.py` already exist. No content changes needed.

## File List

- `bridge/bridge.py` — remove sys.path hack; absolute package imports; cleaned `__all__`; E402 noqa removed
- `bridge/core/ap_client.py` — relative imports
- `bridge/core/loops.py` — relative imports
- `bridge/core/mercure.py` — relative imports
- `bridge/core/reachable.py` — relative imports
- `bridge/core/rest.py` — relative imports
- `bridge/core/save_parser.py` — relative imports
- `bridge/core/state.py` — relative imports
- `bridge/core/wake_on_connect.py` — relative imports
- `bridge/pyproject.toml` — remove `mypy_path` stopgap
- `bridge/tests/*.py` — update private symbol imports to canonical module paths (per AC3 table)
- `.github/workflows/backend.yml` — add entry point smoke test step
- `_bmad-output/implementation-artifacts/20-3-bridge-fix-syspath-imports.md` — this file

## Change Log

| Date       | Change                                                                                      |
|------------|---------------------------------------------------------------------------------------------|
| 2026-05-15 | Story created                                                                               |
| 2026-05-15 | Revised: CI smoke test required for both entry points; private symbol migration table added; Docker CWD constraint documented |
