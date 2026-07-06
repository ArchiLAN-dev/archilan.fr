# Story 33.15: ClockInterface Migration (api/)

Status: ready-for-dev

## Story

As a maintainer of the api's Application layer,
I want the zero-arg `new \DateTimeImmutable()` clock reads replaced by an injected `Psr\Clock\ClockInterface`,
so that time is deterministic in tests (MockClock), the no-magic rule (root CLAUDE.md: "no `date()`/`time()`/`rand()` in application logic") is honoured for `new \DateTime*` too, and the validator can enforce it.

## Context

126 zero-arg `new \DateTimeImmutable()`/`new \DateTime()` sites across ~73 Application files, 11 contexts
(33.5 worklist §D). Scoped scan: only ONE site is inside a static method (`AdminRegistrationExporter`);
the other 125 are instance methods where `$this->clock` is available. `symfony/clock` is already a
dependency (used in 33.6) and autowires `ClockInterface` - no services.yaml wiring needed. `Sessions`
(6 sites) is EXCLUDED (frozen until Epic 32), consistent with the taxonomy carve-out.

## Acceptance Criteria

1. **AC1 - Clock injected, not `new`.** Every zero-arg `new \DateTimeImmutable()`/`new \DateTime()` in a
   non-Sessions Application class replaced by `$this->clock->now()` with an injected
   `Psr\Clock\ClockInterface $clock`. Date construction FROM data (`new \DateTimeImmutable($string)`) is left
   untouched (not a clock read). The 1 static case handled by passing the clock/`now` as a parameter.
2. **AC2 - Tests deterministic.** Unit tests instantiating a migrated service pass a clock -
   `Symfony\Component\Clock\MockClock` (fixed instant) where the time value is asserted, else a plain
   `MockClock`. No test relies on wall-clock `now`.
3. **AC3 - Validator enforces it.** The Application no-clock rule (currently `date()`/`time()`/`rand()`/
   `mt_rand()`) is extended to flag zero-arg `new \DateTime`/`new \DateTimeImmutable` in Application
   (Sessions exempt via the existing allowlist mechanism). Unit tests for the new rule.
4. **AC4 - Sessions excluded.** Sessions' 6 sites untouched; recorded as part of the Epic-32 Sessions
   follow-up (33.20).
5. **AC5 - All gates green; zero behaviour change.** `composer gates` green; the injected default clock
   (`Symfony\Component\Clock\Clock`) returns the same `now` as `new \DateTimeImmutable()` did, so production
   behaviour is identical.

## Tasks / Subtasks

- [ ] Task 1: Commit story + pilot one context (Payments) end-to-end to fix the pattern (source + tests) (AC: 1, 2)
- [ ] Task 2: Migrate the remaining contexts in batches (Community, Identity, Events, Registrations, PersonalRuns, Membership, GameSelection, CatalogSync, Content) - inject clock, `$this->clock->now()`, MockClock in tests (AC: 1, 2)
- [ ] Task 3: Handle the 1 static site (`AdminRegistrationExporter`) (AC: 1)
- [ ] Task 4: Extend the validator no-clock rule + unit tests (AC: 3)
- [ ] Task 5: Gates + PR (AC: 5)

## Dev Notes

- **Pattern:** add `private readonly ClockInterface $clock` (or promoted `private ClockInterface $clock` matching
  the class's constructor style) as a constructor param; `use Psr\Clock\ClockInterface;`; replace the call.
  Classes with no constructor gain one. Message handlers / Command / Query / Service classes all follow the same shape.
- **Autowiring:** Symfony 7 registers `Symfony\Component\Clock\Clock` as the default `ClockInterface` - no
  services.yaml change. Verify with `lint:container`.
- **Tests:** `new MockClock('2026-05-01T10:00:00+00:00')` when the exact instant is asserted (a lot of the
  existing tests build `$now = new \DateTimeImmutable(...)` and pass it to the service - now the service reads
  its own clock, so the test sets the MockClock to that instant instead). Where the value is not asserted,
  a bare `new MockClock()` suffices.
- **Not a clock read (leave alone):** `new \DateTimeImmutable($order['date'])`, `->modify(...)` on a clock
  result stays, `new \DateTimeImmutable($string)` parsing.
- Windows/exec lessons (memory) apply for any scripting; prefer the Edit tool for per-file precision.
- Infection (33.12) benefits: deterministic time = clock-related mutants become killable.

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date | Change |
|------|--------|
| 2026-07-06 | Story created (epic-33 follow-up 33.15). 126 sites / ~73 files / 11 contexts (Sessions excluded); only 1 static site; symfony/clock autowires. Pilot-then-batch by context. Status: ready-for-dev. |
