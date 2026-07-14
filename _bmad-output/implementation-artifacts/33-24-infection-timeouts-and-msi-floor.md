# Story 33.24: Infection - Explain the 141 Timeouts, Set a Defensible MSI Floor (api/)

Status: ready-for-dev

## Story

As a maintainer of the quality gates,
I want the 141 mutation-testing timeouts explained and a floor set on a number we actually trust,
so that Infection can stop being a report nobody reads and become a gate - or be honestly retired.

Origin: **epic-33 retrospective, action A5.** It is the one advisory gate of the four that could *not*
be flipped, and the only reason it exists as a story is that A5 refused to leave a promise in the CI
workflow with nobody behind it.

## Context - why this could not just be flipped

Story 33.12 adopted Infection and recorded its baseline honestly:

> *Community context, unit suite: Mutation Code Coverage 100%, **Covered-Code MSI 72%**, 9m16s,
> **141 timeouts** (30s each).*

and then recorded the follow-up it never did:

> *"investigate [the timeouts] before making the gate blocking"*

**Nobody did.** The CI step has sat at `--min-msi=0` with `|| true` **and** `continue-on-error: true`
ever since - it is triply unable to fail. A5 (2026-07-14) flipped the other three advisory checks
(Rector, `composer audit`, `pnpm audit`) to hard gates because they were genuinely ready. This one is
not, and saying so out loud is the point:

**Raising `--min-msi` on top of 141 unexplained timeouts would gate the build on a number nobody trusts.**

A timeout is not a survived mutant and not a killed one - it is a *measurement failure*. Until they are
explained, the MSI is a number with an asterisk, and a floor built on it is theatre.

## The two possible explanations (this story picks one, with evidence)

1. **Legitimate.** The mutants create infinite/very slow loops (e.g. flipping a `<` to `<=` in a
   `while`), and 30s is a correct kill signal. If so: timeouts should be *counted as kills*, the real
   MSI is higher than 72%, and a floor can be set on it.
2. **Configuration.** pcov/threads/timeout interact badly - e.g. `threads: max` on a 30s timeout under
   pcov starves the workers and healthy mutants time out. If so: the 72% is *understated by an unknown
   amount*, and fixing the config changes the number before any floor is meaningful.

These have opposite consequences for the floor, which is exactly why guessing is not allowed.

## Acceptance Criteria

1. **AC1 - The timeouts are explained, with evidence.** Run the baseline again and capture the actual
   timed-out mutants (`--logger-html` or the JSON logger). Classify them: infinite-loop mutants
   (legitimate) vs mutants that should have completed. State which of the two explanations above holds,
   with counts. **A guess is not an answer.**

2. **AC2 - The measurement is fixed if it is broken.** If the timeouts are a config artefact (per-mutant
   `timeout: 30`, `threads: max`, pcov), tune it and re-measure. The deliverable is a run whose timeout
   count is *understood*, not necessarily zero.

3. **AC3 - A floor on a trusted number.** With the measurement sound, set `minMsi` / `minCoveredMsi` in
   `infection.json5` at the measured value minus a documented margin (the 33.12 coverage-floor precedent:
   baseline 67.84% → floor 65).

4. **AC4 - The gate flips, or the story says why not.** Either drop `continue-on-error` + the `|| true`
   from the CI step (and remove `--min-msi=0`), **or** record - in the workflow comment and here - the
   concrete reason it stays advisory and what would change that. **No third option.** An advisory gate
   with an unowned "we'll flip it later" is what this story exists to end.

5. **AC5 - Scope stays diff-scoped in CI.** The CI step runs `--git-diff-lines` against `develop`; that
   stays. The baseline work is a full-context run, done locally/one-off - do not make CI slow.

6. **AC6 - All gates green.** `composer gates` (5 legs incl. Rector) + `pnpm gates`.

## Tasks / Subtasks

- [ ] **T1 - Reproduce the baseline with a logger (AC1).** `infection.json5` currently has
      `timeout: 30`, `minMsi: 0`. Run the Community/unit baseline with the JSON or HTML logger and get
      the *list* of timed-out mutants, not just the count.
- [ ] **T2 - Classify them (AC1).** Infinite-loop mutants vs should-have-finished. Read a handful of the
      actual mutant diffs - the answer will be obvious from three or four of them.
- [ ] **T3 - Fix the measurement if broken (AC2).** Suspects, in order: `threads: max` under pcov;
      per-mutant `timeout: 30` being generous enough to mask a starved worker rather than kill a loop.
- [ ] **T4 - Set the floor (AC3).** Measured MSI minus a stated margin, in `infection.json5`.
- [ ] **T5 - Flip the gate, or write down why not (AC4).** Update the CI comment either way. It currently
      points at THIS story - if this story ends without a verdict, that comment becomes a lie, which is
      precisely the failure mode A1 turned into a test.

## Dev Notes

- **Environment gotcha, recorded by 33.12:** the local PHP has no pcov/xdebug, so Infection cannot run
  bare-metal locally. 33.12 used a throwaway pcov Docker image, which itself needed two fixes: `zip` was
  missing, and **the Windows bind-mount could not re-read Infection's coverage-xml, so `var/` had to move
  to a container tmpfs.** Budget for that before blaming Infection.
- The suite has grown a lot since the 33.12 baseline (1460 → 1529 tests) and the phpunit `unit`/`functional`
  split 33.12 introduced is what makes a fast mutation run possible at all. Re-measure; do not reuse the
  old number.
- If the verdict is "retire it": say so. A tool that reports into a void costs CI minutes and buys nothing.
  That is a legitimate outcome of this story, not a failure.

### References

- Epic-33 retrospective, action **A5**: `epic-33-retro-2026-07-13.md`.
- The baseline and the un-actioned follow-up: `33-12-mutation-testing-infection.md` (AC4).
- The CI step this story unblocks: `.github/workflows/backend.yml`, "Mutation testing (advisory)".

## Dev Agent Record

### Agent Model Used

### Completion Notes List

### File List

## Change Log

| Date | Change |
|------|--------|
| 2026-07-14 | Story created by retro action A5. Three of the four advisory gates (Rector, composer audit, pnpm audit) were flipped to hard gates; Infection could not be, because its baseline carries 141 unexplained timeouts and a floor built on an untrusted number is theatre. This story exists so the CI comment has something real behind it instead of an unowned promise. Status: ready-for-dev. |
