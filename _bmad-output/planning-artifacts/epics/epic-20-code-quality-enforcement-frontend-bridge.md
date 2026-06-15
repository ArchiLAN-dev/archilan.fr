# Epic 20: Code Quality Enforcement - Frontend & Bridge

Mirror the quality discipline of Epic 19 (API layer) on the two remaining stacks: the Next.js frontend and the Python bridge. Both stacks already have working code and passing basics (typecheck/lint/build for the frontend, a full pytest suite for the bridge), but neither has the same depth of static analysis, architectural constraints, or structural hygiene enforced by automated gates. Stories 20.1–20.4 address the bridge; stories 20.5–20.8 address the frontend.

## New Requirements

### Non-Functional Requirements

NFR-BRIDGE1: The bridge has `ruff` and `mypy` running as CI quality gates with zero violations.
NFR-BRIDGE2: The bridge has no module-level mutable globals - all runtime state flows through explicit objects injected at construction time.
NFR-BRIDGE3: The bridge uses proper Python package imports - no `sys.path` manipulation at startup (the `sys.path` hack in `bridge.py` is removed; `save_parser.py`'s AP-source injection is a different concern and out of scope).
NFR-BRIDGE4: `rest.py` route handlers are extracted from the single `create_app` closure into named coroutines, each independently testable.
NFR-FE1: No `process.env` access outside `src/lib/env.ts` in the frontend (enforced by ESLint).
NFR-FE2: No `as SomeThing` type assertions at API response boundaries in the frontend (enforced by ESLint + type guard audit).
NFR-FE3: Every `*-api.ts` file has a corresponding Jest unit test file.
NFR-FE4: The frontend `QueryClient` and its `staleTime` / `gcTime` defaults are defined in a single shared location.

### NFR Coverage Map

NFR-BRIDGE1: Story 20.1 - ruff + mypy as bridge quality gates.
NFR-BRIDGE2: Story 20.2 - eliminate module-level mutable state from rest.py.
NFR-BRIDGE3: Story 20.3 - fix sys.path import hack in bridge.py.
NFR-BRIDGE4: Story 20.4 - extract rest.py route handlers into named coroutines.
NFR-FE1: Story 20.5 - ESLint rule banning process.env outside env.ts.
NFR-FE2: Story 20.6 - type-guard completeness audit + ESLint assertion-style enforcement.
NFR-FE3: Story 20.7 - Jest unit test suite for the API layer.
NFR-FE4: Story 20.8 - centralise QueryClient configuration.

---

## Story 20.1: Ruff + Mypy as Bridge Quality Gates

As a developer,
I want `ruff check` and `mypy` to run as mandatory CI quality gates on the bridge,
So that Python style violations and type errors are caught before merge, mirroring PHPStan + CS Fixer on the API.

**Acceptance Criteria:**

**Given** `ruff` is configured in `pyproject.toml` (rule set already present)
**When** story 20.1 is complete
**Then** `ruff` and `mypy` are added to `bridge/requirements.txt` and both run in CI
**And** `ruff check bridge/` exits 0 - all existing violations (including `PLW0603` global-statement warnings, `PLC0415` import-not-at-top) are resolved or annotated with a `# noqa:` and an explanation comment
**And** `mypy` is configured in `pyproject.toml` with at minimum `disallow_untyped_defs = true` and `ignore_missing_imports = true` (broad stopgap only - suppresses all unresolved import errors including internal ones; must be narrowed to per-module overrides for external packages once Story 20.3 fixes the package structure)
**And** `mypy bridge/` exits 0 - all public function and method signatures carry type annotations
**And** both commands are added to the CI bridge job
**And** the full existing bridge test suite passes unchanged

---

## Story 20.2: Eliminate Module-Level Mutable State from rest.py

As a developer,
I want runtime pause/wake coordination state to live in an explicit object rather than module globals,
So that bridge modules have no hidden shared state and tests can instantiate multiple app instances in isolation.

**Context:**
`rest.py` currently holds two module-level mutables mutated via `global` statements inside `_pause_flow` and `_cancel_wake_task`:
```python
_wake_stop_event: asyncio.Event | None = None
_wake_task: "asyncio.Task[None] | None" = None
```
This is the Python equivalent of the static mutable properties banned in the API CLAUDE.md.

**Acceptance Criteria:**

**Given** the pause/wake coordination state is module-global today
**When** story 20.2 is complete
**Then** a `PauseResumeCoordinator` dataclass is introduced in `core/coordinator.py` holding `wake_stop_event` and `wake_task` as instance attributes
**And** `create_app` receives a `coordinator: PauseResumeCoordinator` parameter (defaulting to a new instance if omitted, for backwards compatibility)
**And** `_pause_flow` and `_cancel_wake_task` receive the coordinator as a parameter - no `global` statements remain in `rest.py`
**And** all callers of `create_app()` continue to work without signature changes; tests that previously accessed `_rest._wake_stop_event` / `_rest._wake_task` directly are updated to read the coordinator from the app
**And** `ruff check`, `mypy`, and `pytest` all pass

---

## Story 20.3: Fix sys.path Import Hack in bridge.py

As a developer,
I want bridge modules to be importable via proper Python package paths,
So that `import bridge.core.config` works correctly and mypy and ruff resolve symbols without path magic.

**Context:**
`bridge.py` currently inserts `core/` into `sys.path` so internal modules can do `from config import Config`. This breaks mypy's module graph, confuses IDEs, and makes internal imports indistinguishable from stdlib imports. The fix is relative imports inside `bridge/core/` and absolute package imports in `bridge/bridge.py`.

**Acceptance Criteria:**

**Given** all core modules use sibling imports (`from config import Config`, `from state import StateManager`)
**When** story 20.3 is complete
**Then** all imports inside `bridge/core/*.py` use relative imports (`from .config import Config`, `from .state import StateManager`)
**And** `bridge/bridge.py` removes the `sys.path.insert` block entirely
**And** private symbols (`_build_feed_event`, `_PRINT_TYPE_MAP`, `_WS_RETRY_DELAYS`, `_compute_reachable`, `_daemon_ready_events`, `_reachable_cache`, `_reachable_daemons`, `_start_daemon`) are removed from both `__all__` **and** the top-level `import` statements in `bridge.py` - removing from `__all__` alone is insufficient because symbols remain importable as long as they exist at module top-level
**And** `python -m bridge.bridge` and `python bridge/bridge.py` both run from the repo root with no `ImportError` or `ModuleNotFoundError` (both verified as explicit CI steps - no `|| true` masking)
**And** the global `ignore_missing_imports = true` stopgap added in Story 20.1 is removed from `[tool.mypy]`; per-module `[[tool.mypy.overrides]]` entries replace it for external packages that genuinely lack stubs; any internal module that now fails to resolve indicates a missed relative-import conversion and must be fixed
**And** `mypy`, `ruff`, and `pytest` all pass

---

## Story 20.4: Extract rest.py Route Handlers into Named Coroutines

As a developer,
I want each REST route handler in `rest.py` to be a named async function rather than a closure nested inside `create_app`,
So that each handler is independently readable, testable in isolation, and fully analysable by mypy.

**Context:**
`create_app` in `rest.py` is ~300 lines with 10 route handlers defined as nested `async def` closures. Closures capture `state`, `ap_client`, `log`, and `reachable_semaphore` by reference, making it impossible to unit-test a single handler without invoking the full factory. The API's controller-per-action pattern is the reference model.

**Acceptance Criteria:**

**Given** all route handlers (`health`, `get_state`, `post_command` on `/commands`, `get_hints` on `/hints/{slot}`, `request_hint` on `/hints/{slot}/request`, `get_reachable` on `/reachable/{slot}`, `get_item_locations` on `/item-locations/{slot}`, `post_save`, `post_pause`, `post_resume`) are closures inside `create_app`
**When** story 20.4 is complete
**Then** each handler is extracted to a module-level `async def` function receiving its dependencies as parameters or reading them from `request.app`
**And** `AppKey` constants are extracted to a new `rest_keys.py` (imported by both `rest.py` and handler modules to avoid a circular import); handlers are always split by domain into `rest_session.py`, `rest_hints.py`, and `rest_reachable.py`; `rest.py` is reduced to `create_app` as the assembly point (assembly only: imports from `rest_keys` and handler modules, wires routes - no handler logic, no key definitions)
**And** at least 3 handlers gain dedicated unit tests in `tests/test_rest_handlers.py` covering a success path and one error path each, plus a route parity test
**And** `mypy`, `ruff`, and `pytest` (full suite + new tests) all pass

---

## Story 20.5: ESLint Rule - Ban process.env Outside env.ts

As a developer,
I want an ESLint rule that rejects any `process.env` access outside `src/lib/env.ts`,
So that AC-ENV1 is machine-enforced and cannot be violated silently by future code.

**Context:**
`AGENTS.md` AC-ENV1: "Never access `process.env` directly. Always go through `src/lib/env.ts`." This is currently convention-only. A grep audit verifies the current state; then an ESLint rule locks it permanently.

**Acceptance Criteria:**

**Given** `process.env` may exist in files other than `src/lib/env.ts`
**When** the audit is complete
**Then** every `process.env` access outside `src/lib/env.ts` in non-test files is replaced by the appropriate `env.*` accessor (test files `**/*.test.ts` and `**/*.test.tsx` are excluded from the audit - they intentionally use `process.env` for MSW base URL setup)

**When** all violations are resolved
**Then** three `no-restricted-syntax` selectors are added to `eslint.config.*`, scoped to `src/**/*.{ts,tsx}` and excluding `src/lib/env.ts` and test files via the `ignores` field: dot-access (`process.env.FOO`), computed access (`process["env"].FOO`), and destructuring (`const/let/var { FOO } = process.env`); optional chaining is the one accepted gap, documented explicitly
**And** `pnpm lint` exits 0 with 0 warnings
**And** `pnpm typecheck` and `pnpm build` remain clean

---

## Story 20.6: Type-Guard Completeness Audit + ESLint Assertion Enforcement

As a developer,
I want all API response parse sites to use type guard functions rather than `as` casts,
So that AC-TS3 is verified to be fully respected and cannot regress.

**Context:**
`AGENTS.md` AC-TS3: "Never use `as SomeType` at API boundaries. All API responses are `unknown` until validated by a type guard function (`function isX(v: unknown): v is X`)." Each `*-api.ts` file should expose an `is{TypeName}` guard and return from it.

**Acceptance Criteria:**

**Given** all `src/features/**/*-api.ts` files parse API responses
**When** the audit is complete
**Then** every `as SomeType` cast on an API response value is replaced by an `is{TypeName}(payload)` type guard in the same file
**And** the ESLint rule `@typescript-eslint/consistent-type-assertions` is configured with `assertionStyle: "never"` scoped to `src/features/**/*-api.ts`
**And** `pnpm lint` exits 0 with 0 warnings
**And** `pnpm typecheck` and `pnpm build` remain clean

---

## Story 20.7: Jest Unit Test Suite for the API Layer

As a developer,
I want every `*-api.ts` file to have a corresponding Jest unit test file,
So that fetch logic, type guards, and error handling are verified independently of the browser and the running API.

**Context:**
The frontend currently has zero automated tests. The `src/features/**/*-api.ts` files are the highest-value first target: they contain fetch logic, type guards, and null-return error handling - all testable as pure functions with `fetch` mocked via MSW or `jest.fn()`.

**Acceptance Criteria:**

**Given** Jest is not yet installed
**When** story 20.7 begins
**Then** Jest is configured with the Next.js Jest preset (`next/jest`) and added to `package.json` as `pnpm test`
**And** MSW is added for network-level fetch mocking

**When** configuration is complete
**Then** each `src/features/**/*-api.ts` file has a sibling `*-api.test.ts` covering:
- Happy path: mock returns valid JSON → type guard passes → typed value returned
- Network error: fetch rejects → function returns `null`
- Malformed response: JSON parses but type guard fails → function returns `null`

**And** `pnpm test` runs all suites and exits 0
**And** `pnpm typecheck`, `pnpm lint`, and `pnpm build` remain clean

---

## Story 20.8: Centralise QueryClient Configuration

As a developer,
I want `QueryClient` instantiation and default `staleTime` / `gcTime` values defined in a single file,
So that caching behaviour is consistent across all features and changing a global default is a one-line edit.

**Context:**
`AGENTS.md` AC-API5 requires `staleTime` to be set explicitly on every `useQuery` call. Without a shared constant file, each call site chooses its own magic number. A `src/lib/query-client.ts` module exports the `QueryClient` factory and named time constants.

**Acceptance Criteria:**

**Given** `QueryClient` may be instantiated in multiple places and `staleTime` is set as a magic number at each `useQuery` call site
**When** story 20.8 begins
**Then** a grep audit identifies all `new QueryClient(...)` call sites and all raw `staleTime` / `gcTime` numeric literals

**When** the audit is complete
**Then** `src/lib/query-client.ts` is created exporting:
```ts
export const DEFAULT_STALE_TIME = 30_000;    // 30 s - standard catalog data
export const REALTIME_STALE_TIME = 5_000;    // 5 s - live session state
export const SESSION_STALE_TIME = 60_000;    // 60 s - session-level polling
export const STATIC_STALE_TIME = Infinity;   // legal pages, configuration
export const DEFAULT_GC_TIME = 300_000;  // 5 min (300 s) - garbage collection window

export function makeQueryClient(): QueryClient { ... }
```
**And** all `new QueryClient(...)` call sites use `makeQueryClient()`
**And** raw `staleTime` and `gcTime` numeric literals in `useQuery` and `useInfiniteQuery` calls are replaced by named constants
**And** `pnpm typecheck`, `pnpm lint`, and `pnpm build` remain clean

---
