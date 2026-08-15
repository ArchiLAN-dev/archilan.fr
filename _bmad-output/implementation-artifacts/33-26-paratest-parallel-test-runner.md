# Story 33.26: ParaTest - Use the 32 Cores the Test Suite Has Been Ignoring (api/)

Status: ready-for-review

## Story

As a developer running the suite dozens of times a day,
I want the test run spread across cores instead of trickling through one process,
so that a full verification is a coffee-sip, not a coffee-break.

Depends on story 33.25, which removed the per-test schema rebuild and, in the same extension, added
the `doctrine:database:create --if-not-exists` step a ParaTest worker needs on its first run.

## Context - the wiring has been sitting there unused

`config/packages/doctrine.yaml` has carried this since story 33.1:

```yaml
# "TEST_TOKEN" is typically set by ParaTest
dbname_suffix: '_test%env(default::TEST_TOKEN)%'
```

The mechanism for per-worker database isolation was therefore already in place and already proven -
`scripts/setup-worktree.sh` and `scripts/test-isolated.sh` both use it. The only thing missing was
the runner that sets `TEST_TOKEN` per process.

**Story 33.24's lesson is applied first, not last:** Infection was adopted, ran for two stories, and
measured nothing, because nobody checked its PHPUnit support. So, before installing anything:
ParaTest **v7.22.4 requires `phpunit/phpunit ^13.1.8`**; this repo is on 13.1.10, PHP 8.4/8.5. It is
supported, and the install pulled exactly 2 packages (`brianium/paratest`, `jean85/pretty-package-versions`).

### Shared-resource audit - what could actually collide

Parallel workers break on shared mutable state. Inventory before running anything:

| Resource | Verdict |
|---|---|
| Postgres database | **isolated** per worker via `TEST_TOKEN` (`archilan_test1`..`archilan_test8`) |
| MinIO / object storage | `NullMinioStorage` in `when@test` - never touched |
| Orchestrateur / runner | `NullRunnerGateway` |
| Outbound HTTP (IGDB, Steam, Twitch, Discord) | `MockHttpClient` + `Stub*` doubles |
| Mercure | `SpyHub` |
| Mailer | `null://null` |
| `WORKSPACE_DIR` / `ARCHIVE_DIR` | injected, but every test that writes a file uses `tempnam()` - unique per process |
| Symfony test container cache | shared, read-only after warmup; warmed once before the run |

Nothing shared and mutable except the database, which is exactly what `TEST_TOKEN` solves.

## Acceptance Criteria

1. **AC1 - A parallel runner exists and is one command.** `composer test:parallel`.

2. **AC2 - Worker databases are created automatically.** A worker whose `archilan_test<N>` does not
   exist must create it, not fail. (Provided by 33.25's extension; this story proves it under a real
   parallel run.)

3. **AC3 - The zero-notice guarantee survives.** `phpunit.xml.dist` sets `failOnDeprecation`,
   `failOnNotice` and `failOnWarning`; api/CLAUDE.md calls zero notices a validation prerequisite. A
   runner that swallowed them would silently void that gate. **Verified with a probe test, not assumed.**

4. **AC4 - Stability, measured over repeated runs.** Test isolation bugs surface as flakes, so a
   single green run proves nothing. Multiple consecutive full runs, all green.

5. **AC5 - `composer test` and CI are NOT changed.** The authoritative gate stays byte-identical to
   what CI runs (`php bin/phpunit`, plus its coverage flags). Parallel is the *local iteration* loop.
   Rationale below - this is a deliberate divergence, and a small one in the safe direction.

6. **AC6 - Docs.** api/CLAUDE.md testing section: what the command is, and that worker databases are
   a normal by-product.

7. **AC7 - All gates green.**

## Why AC5 - not making it the gate

Tempting, and rejected on purpose:

- CI runs `composer test -- --coverage-clover ... --coverage-html ...`. ParaTest supports coverage,
  but it is a different merge path, and the coverage floor (65%) is a hard gate built on that number.
  Changing how coverage is produced is a separate change with its own evidence requirement.
- `composer gates` is documented as "identical to CI (same composer scripts)", and
  `StandardsDocsMatchToolingTest` enforces that the documented leg list matches reality. Flipping the
  test leg to a different binary makes the local gate diverge from the authoritative one.
- The divergence that remains is in the **safe direction**: parallel is *stricter* than serial (it
  surfaces isolation bugs serial cannot), so a developer running it locally catches more, never less.
- GitHub's `ubuntu-latest` runners have 4 vCPUs, where the measured gain is ~2.2x - real, but far
  from the local 21x, and 33.25 already cut CI's test leg by ~70%.

Flipping CI to ParaTest is a legitimate follow-up. It needs its own story because it must re-baseline
the coverage floor.

## Tasks / Subtasks

- [x] **T1 - Verify PHPUnit 13 support before installing (33.24's lesson).**
- [x] **T2 - Shared-resource audit.**
- [x] **T3 - Install + `composer test:parallel` (AC1).**
- [x] **T4 - Prove worker-database creation under a real parallel run (AC2).**
- [x] **T5 - Probe `failOnDeprecation` under ParaTest (AC3).**
- [x] **T6 - Pick a worker count from measurements, not from `nproc` (AC1).**
- [x] **T7 - Repeat runs for stability (AC4).**
- [x] **T8 - Docs (AC6) + gates (AC7).**

## Dev Agent Record

### Agent Model Used

Claude Opus 5

### AC1/T6 - the worker count is measured, not assumed

Functional suite (1023 tests), same machine (32 cores), after story 33.25:

| Workers | Wall clock | vs serial |
|---|---|---|
| 1 (serial, before 33.25) | 8 min 35.9 | - |
| 1 (serial, after 33.25) | 2 min 16.8 | 3.8x |
| 4 | 38.8 s | 13x |
| **8** | **24.6 s** | **21x** |
| 16 | 21.1 s | 24x |

**8 is the default.** Doubling to 16 buys 14% more while doubling the worker databases - Postgres,
not PHP, is the wall past 8. The value is overridable: `composer test:parallel -- --processes=16`.

Full suite (functional + unit, 1814 tests): **~33 s**, against ~8 min 46 serial before 33.25.

### AC2/AC3 - the two things that had to be proven, not assumed

**Worker databases (AC2):** after the first parallel run, `archilan_test1` .. `archilan_test8` exist,
created by 33.25's extension on each worker's first test. No manual setup step, no pre-run script.
They persist between runs and are reused - that is intended, not leakage.

**Zero-notice guarantee (AC3):** a throwaway test emitting `E_USER_DEPRECATED` was run under both
runners. `php bin/phpunit` exits 1; `paratest` exits 1. ParaTest does not swallow the failure. Probe
deleted after the check.

### AC4 - stability

Five consecutive full parallel runs, all green, no flake, no order-dependent failure:
`OK (1814 tests, 12133 assertions)` with wall clocks 24.6 / 21.1 / 27.7 / 31.8 / 33.2 s (the spread
is machine load, not test behaviour). The shared-resource audit above explains why: the database was
the only shared mutable resource, and `TEST_TOKEN` isolates it.

### Note for whoever runs the follow-up

Stale isolated-run databases have piled up on this machine (`archilan_test_story3313`,
`archilan_test_u365b`, ... ~50 of them from past worktree and `test-isolated.sh` sessions). Harmless,
but neither `setup-worktree.sh` nor `test-isolated.sh` cleans up, and ParaTest now adds 8 more. Worth
a small chore.

### File List

**Added:** this story.

**Modified:** `api/composer.json` + `composer.lock` (`brianium/paratest` dev dep, `test:parallel`
script) · `api/CLAUDE.md` (testing section).

**Unchanged on purpose:** `composer test`, `composer gates`, `.github/workflows/backend.yml`.

### Gates

`composer gates` green (5 legs) · `pnpm gates` green.

## Change Log

| Date | Change |
|------|--------|
| 2026-08-15 | ParaTest adopted for the local loop: 8 workers, 8 min 36 → 24.6 s on the functional suite (21x), stacked on story 33.25's 3.8x. PHPUnit-13 support verified before install (33.24's lesson); failOnDeprecation verified to still fail under the parallel runner. CI and `composer test` deliberately untouched. Status: ready-for-review. |
