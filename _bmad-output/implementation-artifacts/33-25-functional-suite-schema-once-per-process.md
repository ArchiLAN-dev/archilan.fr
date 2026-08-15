# Story 33.25: Functional Suite - Build the Schema Once Per Process, Not Once Per Test (api/)

Status: ready-for-review

## Story

As a developer running `composer gates` a dozen times a day,
I want the functional suite to stop rebuilding the entire database schema before every single test,
so that the feedback loop is minutes shorter without weakening a single test's isolation.

Origin: **measured, not suspected.** The suite felt slow; the profiling below says exactly why, and
the fix is contained in one file.

## Context - where the time actually goes

Measured on this machine (PHP 8.4.12, Postgres 17, 32 cores), before any change:

| Suite | Tests | Wall clock | Per test |
|---|---|---|---|
| `--testsuite unit` | 798 | 9.6 s | 12 ms |
| `--testsuite functional` | 1029 | see AC1 baseline | ~750 ms |
| frontend `jest` | 367 | 4.4 s | - |

The unit suite and the frontend are not the problem. `FunctionalTestCase::setUp()` is: it runs
`DROP SCHEMA public CASCADE` followed by a full `SchemaTool::createSchema()` **before every test
method**. Timed in isolation against the real test database:

```
DROP SCHEMA public CASCADE ; CREATE SCHEMA public :  110 ms
SchemaTool::getCreateSchemaSql (metadata cached)   :    8 ms
SchemaTool::createSchema  (104 statements, 50 entities) : 300 ms
--------------------------------------------------------------
                                                     ~420 ms  x 1029 tests
```

**Roughly 55% of the functional suite is spent building a schema that is identical every time**,
before a single line of test code runs.

The two candidate replacements, measured on the same database:

| Strategy | Cost per test | Notes |
|---|---|---|
| `DROP SCHEMA` + `createSchema` (today) | ~420 ms | |
| `TRUNCATE ... RESTART IDENTITY CASCADE` (50 tables) | ~110 ms cold, far less warm | no new dependency |
| `BEGIN` + `ROLLBACK` | ~1 ms | needs a static connection shared across kernel reboots, i.e. `dama/doctrine-test-bundle` |

**A prototype of the TRUNCATE strategy has already been run against the full suite: 1029 tests,
10 184 assertions, all green, 2 min 27.** No test depended on the schema being recreated. The
prototype is kept at `truncate-prototype.patch` (session scratchpad) purely as evidence; this story
implements it properly.

`dama/doctrine-test-bundle` (the 1 ms row) is deliberately **out of scope**: it adds a dependency,
it requires a static DBAL connection surviving `ensureKernelShutdown()`, and it changes the
semantics of the two application services that open real transactions
(`ReserveRegistration`, `AccountModerationService`). Once the schema rebuild is gone, the remaining
per-test cost is dominated by the kernel reboot, not by the wipe - the marginal gain does not pay
for that risk. Parallelism (story 33.26) is the better next lever.

## Acceptance Criteria

1. **AC1 - The "before" number is measured, not extrapolated.** A full `--testsuite functional` run on
   the unchanged code is recorded here, alongside the "after" number. The story states both.

2. **AC2 - The schema is built once per phpunit process.** `DROP SCHEMA` + `createSchema` runs exactly
   once per process, not once per test. Because it still runs at every process start, an entity or
   mapping change is picked up exactly as reliably as today - there is no stale-schema window.

3. **AC3 - Per-test isolation is unchanged.** Every functional test still begins with an empty
   database, sequences included (`TRUNCATE ... RESTART IDENTITY CASCADE`). All 1029 functional tests
   pass **unmodified** - if any test needs an edit to survive this change, the change is wrong.

4. **AC4 - No static mutable state.** Root `CLAUDE.md` forbids static mutable properties, and no test
   file in this repo has one today. The once-per-process hook therefore lives in a **PHPUnit
   extension** (the `<extensions>` block in `phpunit.xml.dist` is empty and exists for this), not in a
   `private static bool $schemaBuilt` on the base class.

5. **AC5 - `--testsuite unit` stays DB-free.** The extension must not touch Postgres when no functional
   test runs. The unit suite runs today with no database at all; that must remain true, and be verified
   by running it against a *dropped* test database.

6. **AC6 - The docs stop describing the old mechanism.** Three places currently assert the per-test
   rebuild and would become lies:
   - `api/CLAUDE.md` AC-T7/AC-T8 ("Schema created per test class with `SchemaTool::createSchema([...])`",
     already inaccurate - it is per *test*, with the full entity set);
   - root `CLAUDE.md`, "Sessions parallèles" (the rationale for per-worktree DB isolation);
   - `api/scripts/test-isolated.sh` header.

   Note the isolation *rationale* survives the change intact: the schema is still dropped at each
   process start, so two processes sharing one database still destroy each other. Weaken that wording
   and the next mass-failure comes back.

7. **AC7 - All gates green.** `composer gates` (5 legs) on an isolated database, plus `pnpm gates`
   untouched.

## Tasks / Subtasks

- [x] **T1 - Record the baseline (AC1).** Full `--testsuite functional` on unchanged code.
- [x] **T2 - PHPUnit extension (AC2, AC4, AC5).** Builds the schema on the first functional test of the
      process. Also runs `doctrine:database:create --if-not-exists`, which story 33.26 needs for
      ParaTest worker databases and which costs nothing here.
- [x] **T3 - `FunctionalTestCase::setUp()` (AC3).** Replace the rebuild with a single
      `TRUNCATE ... RESTART IDENTITY CASCADE` over the `public` schema.
- [x] **T4 - Verify (AC1, AC3, AC5).** Full functional suite green; unit suite green with the test
      database dropped.
- [x] **T5 - Docs (AC6).** The three locations above.
- [x] **T6 - Gates (AC7).**

## Dev Notes

- The non-Postgres branch in the current `setUp()` is vestigial: `.env.test` is Postgres, and
  `tests/bootstrap.php` still deletes a `var/test.db` that nothing creates. Do not grow a second code
  path for a platform the suite no longer uses; if the SQLite fallback goes, say so here.
- `TRUNCATE` over 50 tables is dominated by per-file fsync. If the wipe ever shows up in a profile
  again, running the *test* Postgres with `-c fsync=off -c synchronous_commit=off -c
  full_page_writes=off` is the cheap next step - local compose and the CI service both accept it.
  Not done here: it changes infrastructure config for a gain the profile does not yet justify.
- Story 33.24's lesson applies to any tool considered here: check its PHPUnit 13 support *first*.
  Infection was adopted and silently measured nothing for two stories because nobody did.

### References

- Test DB isolation and the `TEST_TOKEN` mechanism: `33-1-test-db-isolation-and-local-ci-gate-parity.md`.
- The `unit`/`functional` split this story leans on: `33-24-infection-timeouts-and-msi-floor.md` (kept
  deliberately when Infection was retired).
- Follow-up: story 33.26 (ParaTest), which depends on T2's database-create step.

## Dev Agent Record

### Agent Model Used

Claude Opus 5

### AC1 - before and after, both measured

| | Tests | Wall clock | Per test |
|---|---|---|---|
| Before (`DROP SCHEMA` + `createSchema` per test) | 1029 | **8 min 35.9** | **501 ms** |
| After (once per process + `TRUNCATE`) | 1023 | **2 min 16.8** | **134 ms** |

**3.7x faster per test, ~6 min 19 saved per full run.**

**Read the test counts honestly:** the baseline was measured on `feature/epic-16-story-14-relance-participant`,
which carries 6 functional tests `develop` does not have (`PersonalRunLifecycleTest`,
`SessionRestartTest`); the "after" run is on this branch, off `develop`. That is a 0.6% difference in
population, which is why the per-test column - immune to it - is the honest comparator. Both runs are
full, real runs; neither number is extrapolated.

An earlier estimate in the session put the baseline near 13 min. It was extrapolated from
`SessionLifecycleTest`, which is heavier than the suite average. The measured 8 min 36 is the number.

**Small runs improved too**, which matters more day-to-day than the full-suite number:
`AccountDeletionTest` (4 tests) went **3.65 s → 1.42 s**. The extension's fixed cost (kernel boot
171 ms + `doctrine:database:create` 146 ms) is repaid by the second test of any run.

### AC2/AC4 - why an extension and not a static flag

The obvious implementation is `private static bool $schemaBuilt` on `FunctionalTestCase`. Root
`CLAUDE.md` forbids static mutable properties *anywhere*, and no test file in this repo has one, so
that idiom would have been the first. A PHPUnit extension holds the same state in an object PHPUnit
instantiates once per process - same effect, rule intact, and it is the hook the empty `<extensions>`
block in `phpunit.xml.dist` was already there for.

The subscriber fires on `PreparationStarted`, which PHPUnit emits in `runBare()` *before*
`invokeBeforeTestHookMethods()` - i.e. strictly before any `setUp()`. Verified in
`vendor/phpunit/phpunit/src/Framework/TestCase.php:483`.

### AC5 - the unit suite is still DB-free, and it was actually tested

`TEST_TOKEN=_unitcheck php bin/phpunit --testsuite unit` - i.e. pointed at `archilan_test_unitcheck`,
a database that **does not exist** - passes: 791 tests, 1990 assertions, 10.3 s. The extension never
touches Postgres because no test extending `FunctionalTestCase` runs.

The converse was tested too, because story 33.26 depends on it:
`TEST_TOKEN=_ac5probe php bin/phpunit tests/Functional/AccountDeletionTest.php` **created**
`archilan_test_ac5probe` and passed. That is exactly what a ParaTest worker will need on its first
run. Probe database dropped afterwards.

### Deliberately not done

- **`dama/doctrine-test-bundle`** (1 ms/test instead of ~15 ms): out of scope, see the Context section.
  With the rebuild gone, the remaining per-test time is the kernel reboot, not the wipe.
- **Postgres `fsync=off` for the test database:** real, cheap, but it changes infrastructure config;
  the profile no longer justifies it.
- The **SQLite fallback** in `setUp()` and the `var/test.db` deletion in `tests/bootstrap.php` are
  **removed**. `.env.test` is Postgres and says so; nothing had created that file in a long time. The
  one remaining SQLite use (`DiscordResyncAllUsersTest`, in-memory) is unrelated and untouched.

### File List

**Added:** `api/tests/Functional/FunctionalSchemaExtension.php` ·
`api/tests/Functional/BuildSchemaOnceSubscriber.php` · this story.

**Modified:** `api/tests/Functional/FunctionalTestCase.php` (rebuild → TRUNCATE) ·
`api/phpunit.xml.dist` (extension registered) · `api/tests/bootstrap.php` (dead SQLite cleanup) ·
`api/CLAUDE.md` (AC-T7/AC-T8) · root `CLAUDE.md` ("Sessions parallèles" rationale) ·
`api/scripts/test-isolated.sh` (header).

**Untouched:** all 1023 functional tests, every other gate, the frontend.

### Gates

`composer gates`, 5 legs: phpstan (level max + strict-rules) 0 errors · cs-fixer 0 violations ·
`app:architecture:ddd` OK · Rector clean · phpunit green (1023 functional + 791 unit).

## Change Log

| Date | Change |
|------|--------|
| 2026-08-15 | Story created from a profiling session: 55% of the functional suite measured as schema rebuild, with a full-suite prototype already green at 2 min 27. Status: draft. |
| 2026-08-15 | Implemented. Schema build moved to a PHPUnit extension (once per process); `setUp()` now TRUNCATEs. Measured 8 min 36 → 2 min 17 (501 → 134 ms per test), no test modified. Unit suite verified DB-free against a non-existent database; worker-database auto-creation verified for story 33.26. Status: ready-for-review. |
