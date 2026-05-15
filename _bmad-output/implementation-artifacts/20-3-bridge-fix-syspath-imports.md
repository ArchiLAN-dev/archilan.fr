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

**AC4:** The two supported entry points are verified by explicit CI steps. `|| true` must NOT be used — it masks import errors. Three checks cover the full surface:

```yaml
# CI step 1 — module mode import (catches ImportError / ModuleNotFoundError at any depth)
- name: Bridge module import smoke test
  working-directory: .
  run: python -c "import bridge.bridge; print('import ok')"

# CI step 2 — syntax check (catches SyntaxError in bridge.py before runtime)
- name: Bridge script syntax check
  working-directory: .
  run: python -m py_compile bridge/bridge.py && echo "syntax ok"

# CI step 3 — script entry point (verify process starts and keeps running)
- name: Bridge script entry point smoke test
  working-directory: .
  run: |
    python bridge/bridge.py 2>bridge_stderr.txt &
    BRIDGE_PID=$!
    sleep 2
    STATUS=0
    if grep -qE "ImportError|ModuleNotFoundError|SyntaxError" bridge_stderr.txt; then
      echo "Import/syntax error in script mode:"; cat bridge_stderr.txt; STATUS=1
    fi
    kill $BRIDGE_PID 2>/dev/null || true
    exit $STATUS
```

Step 1 is the authoritative import check. Step 2 catches syntax errors before runtime. Step 3 provides a defence-in-depth check that the script entry point doesn't crash at startup. Config-driven startup failures (missing env vars) may cause step 3 to exit silently — that is acceptable because config failures are not import failures. Both steps 1 and 2 are deterministic.

All three steps must run from the repo root (the parent of `bridge/`).

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
- [ ] Task 7: Add entry point smoke test to CI (per AC4 — three CI steps)
- [ ] Task 8: Update bridge launch documentation (README or CLAUDE.md) to specify that the bridge must be launched from the repo root; the previous sys.path hack allowed launching from any CWD — this is no longer supported
- [ ] Task 9: Verify quality gates — ruff, mypy, full test suite

## Dev Notes

### Script mode and absolute imports

`python bridge/bridge.py` sets `__package__ = None` and `__name__ = "__main__"`. Relative imports like `from .core.config import Config` require `__package__` to be set — they fail in script mode.

The solution is **absolute imports in `bridge.py`** (`from bridge.core.config import Config`) combined with ensuring the repo root (the parent of `bridge/`) is on `sys.path`.

**There is no `bridge/Dockerfile`.** The bridge is not containerized — `docker-compose.yml` has no bridge service. The bridge runs as a local Python process launched from the repo root. When the process starts from the repo root (CWD = `/path/to/archilan.fr`), Python automatically adds CWD to `sys.path`, so `bridge/` is on the path and `from bridge.core.config import Config` resolves correctly. **No WORKDIR or PYTHONPATH manipulation is needed** — removing the `sys.path.insert` in `bridge.py` is safe as long as the bridge is always started from the repo root.

The CI smoke test must be run from the repo root directory (`working-directory: .`) to replicate this.

### save_parser.py sys.path — out of scope

`bridge/core/save_parser.py` contains a separate `sys.path.insert` that adds the Archipelago server source directory (a runtime dependency, not a sibling module). This is **not a sibling import hack** — it injects an external library path at runtime so AP game definitions can be imported. It is **out of scope for this story**. Do not modify `save_parser.py`'s sys.path logic.

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
- `bridge/core/save_parser.py` — relative imports (`from domain import` → `from .domain import`)
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
| 2026-05-15 | Revised: No bridge/Dockerfile exists — bridge runs as local Python process from repo root; CI smoke uses stderr grep, not `\|\| true`; save_parser.py sys.path explicitly out of scope |
