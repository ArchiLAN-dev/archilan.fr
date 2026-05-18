# Story 20.3: Fix sys.path Import Hack in bridge.py

## Story

**As a** developer,
**I want** bridge modules to be importable via proper Python package paths,
**So that** mypy and ruff resolve symbols correctly and internal imports are distinguishable from stdlib imports.

## Status

review

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

**AC3:** The following private symbols are removed from `bridge/bridge.py` entirely - both from `__all__` **and** from any top-level `import` or `from ... import` statement. Removing only from `__all__` is insufficient: as long as `bridge.py` imports `_reachable_cache` at the top level, `from bridge.bridge import _reachable_cache` remains possible regardless of `__all__`.

After this story, none of the symbols below are imported in `bridge.py`. Every test that imports them is redirected to the canonical source:

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

**AC4:** The two supported entry points are verified by explicit CI steps. `|| true` must NOT be used - it masks import errors. Three checks cover the full surface:

```yaml
# CI step 1 - module mode import (catches ImportError / ModuleNotFoundError at any depth)
- name: Bridge module import smoke test
  working-directory: .
  run: python -c "import bridge.bridge; print('import ok')"

# CI step 2 - syntax check (catches SyntaxError in bridge.py before runtime)
- name: Bridge script syntax check
  working-directory: .
  run: python -m py_compile bridge/bridge.py && echo "syntax ok"

# CI step 3 - script entry point (verify process starts and keeps running)
- name: Bridge script entry point smoke test
  working-directory: .
  run: |
    python bridge/bridge.py 2>bridge_stderr.txt &
    BRIDGE_PID=$!
    sleep 2
    STATUS=0
    # Catch import/syntax errors explicitly
    if grep -qE "ImportError|ModuleNotFoundError|SyntaxError" bridge_stderr.txt; then
      echo "Import/syntax error in script mode:"; cat bridge_stderr.txt; STATUS=1
    fi
    # Catch unexpected tracebacks (config errors or any unhandled exception at startup)
    if grep -q "^Traceback" bridge_stderr.txt; then
      echo "Unexpected traceback in script mode:"; cat bridge_stderr.txt; STATUS=1
    fi
    kill $BRIDGE_PID 2>/dev/null || true
    exit $STATUS
```

**Steps 1 and 2 are authoritative** - they deterministically verify no import or syntax errors. Step 3 is **defence-in-depth only**: the bridge process may exit prematurely due to missing env vars or config issues; that is acceptable because this story concerns import correctness, not startup configuration. A green step 3 is a signal, not a guarantee; a red step 3 (Traceback or ImportError in stderr) is always a failure.

All three steps must run from the repo root (the parent of `bridge/`).

**AC5:** All `# noqa: E402  # temporary - removed in story 20.3` comments placed in Story 20.1 are removed from `bridge.py`. The `mypy_path = "bridge/core"` stopgap from Story 20.1's mypy config is also removed.

**AC6:** `mypy bridge/` exits 0, `ruff check bridge/` exits 0, the full existing test suite passes.

**AC7:** The global `ignore_missing_imports = true` flag is removed from `[tool.mypy]` in `bridge/pyproject.toml`. In its place, only external packages that genuinely lack type stubs get per-module overrides:

```toml
[tool.mypy]
python_version = "3.10"
disallow_untyped_defs = true
# ignore_missing_imports removed - internal resolution now works via proper package structure

[[tool.mypy.overrides]]
module = ["websockets.*"]   # add any other untyped external packages found during audit
ignore_missing_imports = true
```

The list of packages requiring an override is determined by running `mypy bridge/` after removing the global flag and collecting every `Cannot find implementation or library stub` error. External packages (e.g. `websockets`) get an override; internal modules that fail to resolve indicate a missed sibling-import conversion and must be fixed, not suppressed. `aiohttp` ships its own stubs and should not need an override.

## Tasks / Subtasks

- [x] Task 1: Create story file (this file)
- [x] Task 2: Convert `bridge/core/*.py` to relative imports
  - [x] 2a: `core/ap_client.py`
  - [x] 2b: `core/loops.py`
  - [x] 2c: `core/mercure.py`
  - [x] 2d: `core/reachable.py`
  - [x] 2e: `core/rest.py`
  - [x] 2f: `core/save_parser.py`
  - [x] 2g: `core/state.py`
  - [x] 2h: `core/wake_on_connect.py` (no sibling imports - verified)
  - [x] 2i: `core/domain.py` (no sibling imports - verified)
- [x] Task 3: Update `bridge/bridge.py`
  - [x] 3a: Remove `sys.path.insert` block; remove unused `sys` import (`os` retained for `os.path.join` in `_main()`)
  - [x] 3b: Convert all `from config import X` → `from bridge.core.config import X` (all 8 modules converted)
  - [x] 3c: Remove each private symbol in the AC3 table from both `__all__` and its top-level `import` / `from ... import` statement in `bridge.py`
- [x] Task 4: Update test imports
  - [x] 4a: Scanned all test files for private symbol imports and bare module imports (`import rest`, `from coordinator import`, `from wake_on_connect import`)
  - [x] 4b: Redirected to canonical modules: `_reachable_cache` → `bridge.core.reachable`, `_build_feed_event`/`DataPackageStore` → `bridge.core.ap_client`, `rest` → `bridge.core.rest`, `coordinator` → `bridge.core.coordinator`, `wake_on_connect` → `bridge.core.wake_on_connect`; updated all `patch("rest.X")` → `patch("bridge.core.rest.X")`
- [x] Task 5: Remove stopgap artefacts from Story 20.1
  - [x] 5a: Removed all `# noqa: E402  # temporary - removed in story 20.3` comments (bridge.py rewritten)
  - [x] 5b: Removed `mypy_path = "bridge/core"` from `pyproject.toml`
  - [x] 5c: Removed global `ignore_missing_imports = true`; ran `mypy bridge/`; added `[[tool.mypy.overrides]]` for `websockets.*`, `boto3`, `botocore.*`; `mypy bridge/` exits 0
- [x] Task 6: Verify both entry points (script mode + module mode) produce no import errors
- [x] Task 7: Add entry point smoke test to CI (per AC4 - three CI steps)
- [x] Task 8: Update bridge launch documentation (README or CLAUDE.md) to specify that the bridge must be launched from the repo root; the previous sys.path hack allowed launching from any CWD - this is no longer supported
- [x] Task 9: Verify quality gates - ruff, mypy, full test suite

## Dev Notes

### Script mode and absolute imports

`python bridge/bridge.py` sets `__package__ = None` and `__name__ = "__main__"`. Relative imports like `from .core.config import Config` require `__package__` to be set - they fail in script mode.

The solution is **absolute imports in `bridge.py`** (`from bridge.core.config import Config`) combined with ensuring the repo root (the parent of `bridge/`) is on `sys.path`.

**There is no `bridge/Dockerfile`.** The bridge is not containerized - `docker-compose.yml` has no bridge service. The bridge runs as a local Python process launched from the repo root. When the process starts from the repo root (CWD = `/path/to/archilan.fr`), Python automatically adds CWD to `sys.path`, so `bridge/` is on the path and `from bridge.core.config import Config` resolves correctly. **No WORKDIR or PYTHONPATH manipulation is needed** - removing the `sys.path.insert` in `bridge.py` is safe as long as the bridge is always started from the repo root.

The CI smoke test must be run from the repo root directory (`working-directory: .`) to replicate this.

### save_parser.py sys.path - out of scope

`bridge/core/save_parser.py` contains a separate `sys.path.insert` that adds the Archipelago server source directory (a runtime dependency, not a sibling module). This is **not a sibling import hack** - it injects an external library path at runtime so AP game definitions can be imported. It is **out of scope for this story**. Do not modify `save_parser.py`'s sys.path logic.

### `__init__.py` files

`bridge/__init__.py` and `bridge/core/__init__.py` already exist. No content changes needed.

## File List

- `bridge/bridge.py` - remove sys.path hack; absolute package imports; cleaned `__all__`; E402 noqa removed; `sys` import removed
- `bridge/core/ap_client.py` - relative imports
- `bridge/core/loops.py` - relative imports
- `bridge/core/mercure.py` - relative imports
- `bridge/core/reachable.py` - relative imports
- `bridge/core/rest.py` - relative imports
- `bridge/core/save_parser.py` - relative imports (`from domain import` → `from .domain import`)
- `bridge/core/state.py` - relative imports
- `bridge/pyproject.toml` - remove `mypy_path` + global `ignore_missing_imports`; add `pythonpath = [".."]` for pytest; add per-module mypy overrides for external packages; add `exclude = ["bridge/tests/"]`
- `bridge/tests/test_api.py` - `_reachable_cache` → `bridge.core.reachable`
- `bridge/tests/test_feed.py` - `DataPackageStore, _build_feed_event` → `bridge.core.ap_client`
- `bridge/tests/test_pause_endpoint.py` - `import rest` → `bridge.core.rest`; `from coordinator` → `bridge.core.coordinator`; patch strings updated to `bridge.core.rest.X`
- `bridge/tests/test_resume_endpoint.py` - same as test_pause_endpoint.py
- `bridge/tests/test_wake_on_connect.py` - `import rest` → `bridge.core.rest`; `from coordinator` → `bridge.core.coordinator`; `from wake_on_connect` → `bridge.core.wake_on_connect`
- `bridge/CLAUDE.md` - created: documents that bridge must be launched from repo root
- `.github/workflows/backend.yml` - mypy step updated to run from repo root; add three entry point smoke test steps (module import, py_compile, script process check - per AC4)
- `_bmad-output/implementation-artifacts/20-3-bridge-fix-syspath-imports.md` - this file

## Dev Agent Record

### Completion Notes

Implemented in one pass. Key decisions:
- `os` import kept in `bridge.py` (used by `os.path.join` in `_main()`); only `sys` removed.
- `core/coordinator.py`, `core/domain.py`, `core/wake_on_connect.py` had no sibling imports - no changes needed.
- `save_parser.py`'s `sys.path.insert` for `/app/ArchipelagoSrc` left intact (out of scope per Dev Notes).
- `mypy bridge/tests/` excluded - 69 pre-existing type errors in tests were hidden by the old `exclude = ["^tests/"]`. These are out of scope for this story.
- `pythonpath = [".."]` added to pytest config so tests run correctly from `bridge/` directory while resolving `bridge.*` from the repo root.
- `boto3` and `botocore.*` added to mypy overrides (imported in rest.py helpers).
- CI mypy step moved to `working-directory: .` and runs `python -m mypy bridge/ --config-file bridge/pyproject.toml`.
- All 141 tests pass; ruff 0 errors; mypy 0 errors on 14 source files.

## Change Log

| Date       | Change                                                                                      |
|------------|---------------------------------------------------------------------------------------------|
| 2026-05-15 | Story created                                                                               |
| 2026-05-15 | Revised: CI smoke test required for both entry points; private symbol migration table added; Docker CWD constraint documented |
| 2026-05-15 | Revised: No bridge/Dockerfile exists - bridge runs as local Python process from repo root; CI smoke uses stderr grep, not `\|\| true`; save_parser.py sys.path explicitly out of scope |
| 2026-05-15 | Implemented: all tasks complete; story status → review |
