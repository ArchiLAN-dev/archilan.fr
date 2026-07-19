# Story 35.20: Validator rule - command services return void/record/enum, never an array (api/)

Status: done

## Story

As a maintainer completing epic 35 Stage 2,
I want `DddArchitectureValidator` to gate "a command service returns `void`, a `final readonly` record, or an
enum, never a raw `array`", update `api/CLAUDE.md` AC-A3, and convert the last few commands the rule surfaces,
so that the Stage 2 invariant is mechanically enforced and cannot regress.

## Context

Epic 35, Stage 2, final story. Stages 35.8-35.19 converted the command return types context by context; this
story adds the gate that makes the invariant permanent and catches anything the manual sweeps missed.

**The rule found four commands the manual sweeps missed** - all with multi-line signatures (a `): array` on its
own line), which a single-line grep never caught but the validator's offset-attributed scan does:

- `Sessions/Application/Command/RecordSlotGoal` and `WeeklyRuns/Application/Command/RecordWeeklyGoal` -> both
  `array{entryId: string}|null`; `RecordSlotGoal` literally returns `RecordWeeklyGoal`'s result, so one shared
  `WeeklyGoalResult {entryId}` record serves both (the Sessions->WeeklyRuns Application coupling already exists).
- `Membership/Application/Command/AdminCreateMembership` -> a 7-field inline payload -> `MembershipCreated`.
- `Membership/Application/Command/AdminEditMembership` -> `array<string,mixed>|null` **delegated to a read-model
  query** (`AdminMembershipListQuery::findById`, a DBAL 10-field row with joined user/profile columns). Typing
  it is the Membership admin read-model chantier - **deferred to 35.21** and added to the rule's documented
  exemption allowlist.

## Acceptance Criteria

1. **AC1 - Rule.** Add `DddArchitectureValidator::validateCommandArrayReturns`, wired into `validate()`: for each
   `{Context}/Application/Command/` file, every **public** method whose native return type is `array` (or
   `?array`) is a violation. Mirrors `validateApplicationEntityReturns`'s offset-attributed visibility scan
   (private helpers may still marshal arrays; the colocated records/enums have no array-returning public method).
2. **AC2 - Exemption.** `DddArchitectureValidator::COMMAND_ARRAY_RETURN_EXEMPT` lists `AdminEditMembership.php`
   (delegates to the Membership admin read-model; 35.21), with a doc comment.
3. **AC3 - Surfaced commands converted.** `RecordWeeklyGoal`/`RecordSlotGoal` -> `?WeeklyGoalResult` (shared);
   `AdminCreateMembership` -> `MembershipCreated`. Consumers read the records; `SlotGoalCallbackController` passes
   the result through byte-identically (`['data' => $result]`: null->null, record->`{entryId}`);
   `AdminCreateMembershipTest` reads the record fields.
4. **AC4 - Doc.** `api/CLAUDE.md` AC-A3 rewritten: commands return void/record/enum (never a raw array), citing
   `validateCommandArrayReturns` + `COMMAND_ARRAY_RETURN_EXEMPT` + `validateApplicationEntityReturns`. Service
   facades keep the `{found, errors}` outcome contract. `StandardsDocsMatchToolingTest` stays green (the cited
   symbols exist).
5. **AC5 - Gates.** `composer gates` green (the new `app:architecture:ddd` leg reports 0 command-array
   violations, with `ForceEndSessionCommand` fixed by 35.19). Full isolated suite green.

## Tasks / Subtasks

- [x] Task 1: `validateCommandArrayReturns` + wire into `validate()` (AC: 1).
- [x] Task 2: `COMMAND_ARRAY_RETURN_EXEMPT` allowlist for `AdminEditMembership` (AC: 2).
- [x] Task 3: convert `RecordWeeklyGoal`/`RecordSlotGoal`/`AdminCreateMembership` + consumers/test (AC: 3).
- [x] Task 4: rewrite AC-A3; keep the doc-sync test green (AC: 4).
- [x] Task 5: verify + ship (AC: 5). PR to `develop`.

## Dev Notes

- **The rule earns its keep.** Running it immediately flagged four commands (incl. all of Membership, never a
  Stage 2 story) that the per-context manual sweeps had missed - exactly the drift a gate exists to stop.
- **Shared `WeeklyGoalResult`.** `RecordSlotGoal::execute` returns `$this->recordWeeklyGoal->execute(...)`
  verbatim, so it returns the same type; the record lives in WeeklyRuns/Command with `RecordWeeklyGoal`, and
  `RecordSlotGoal` imports it (the Sessions->WeeklyRuns Application dependency already existed).
- **One honest exemption, not a hole.** `AdminEditMembership` returns a DBAL read-model row via
  `AdminMembershipListQuery`; typing it means typing that query's shape (search/findById/findLatestByUserId), a
  read-model story. It is allowlisted with a comment + a follow-up (35.21), mirroring the existing
  `ALLOWED_APPLICATION_ENTITY_RETURNS` pattern - the rule stays enforced for everything else.
- **Rule scope, deliberately narrow.** Only `Application/Command/`, only **public** methods, only the **native**
  `array` return type (phpdoc `@return array{...}` is invisible to `PhpSource::codeText()`, which is fine - all
  Stage 2 commands had explicit `: array`). Service facades (`Application/Service/`) keep their `{found, errors}`
  contract and are out of scope.
- House rules: `final readonly` records, strict types, phpstan max. `composer gates`.

### References

- Convention source: 35.8-35.19; the existing `validateApplicationEntityReturns` (story 33.17) mirrored by the
  new rule.
- Validator: `src/Shared/Application/Support/DddArchitectureValidator.php`.
- Doc: `api/CLAUDE.md` AC-A3 (+ `StandardsDocsMatchToolingTest` auto-verifies the citations).
- Converted: `RecordWeeklyGoal`, `RecordSlotGoal`, `AdminCreateMembership` (+ new `WeeklyGoalResult`,
  `MembershipCreated`).

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- `validateCommandArrayReturns` (mirrors the entity-return scan) + `COMMAND_ARRAY_RETURN_EXEMPT` allowlist added
  to `DddArchitectureValidator` and wired into `validate()`; live `app:architecture:ddd` reports 0 violations.
- Surfaced four missed commands; converted three (`RecordWeeklyGoal`/`RecordSlotGoal` -> shared
  `WeeklyGoalResult`, `AdminCreateMembership` -> `MembershipCreated`), exempted `AdminEditMembership` (read-model,
  -> 35.21).
- Consumers byte-identical: goal callback controllers pass `['data' => $result]` (null/record); membership
  controller pass-through. `AdminCreateMembershipTest` reads `->field`; no other test read the results.
- AC-A3 rewritten (void/record/enum, never array); `StandardsDocsMatchToolingTest` green (3 cited symbols exist).
- `composer gates` green: phpstan max 0, cs-fixer 0, ddd OK (new rule 0 violations), rector OK, phpunit green.
- **Epic 35 Stage 2 complete** - every command returns void/record/enum, gated. Stage 3 stays deferred.

### File List

- `api/src/Shared/Application/Support/DddArchitectureValidator.php` (new rule + exemption const)
- `api/CLAUDE.md` (AC-A3)
- `api/src/WeeklyRuns/Application/Command/WeeklyGoalResult.php` (new)
- `api/src/WeeklyRuns/Application/Command/RecordWeeklyGoal.php` (returns `?WeeklyGoalResult`)
- `api/src/Sessions/Application/Command/RecordSlotGoal.php` (returns `?WeeklyGoalResult`)
- `api/src/Membership/Application/Command/MembershipCreated.php` (new)
- `api/src/Membership/Application/Command/AdminCreateMembership.php` (returns `MembershipCreated`)
- `api/tests/Unit/Membership/AdminCreateMembershipTest.php` (reads the record)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-18 | Story created + implemented (epic 35 Stage 2, final). `validateCommandArrayReturns` rule + exemption; AC-A3 rewritten; three surfaced commands converted, `AdminEditMembership` deferred to 35.21. `composer gates` green. **Stage 2 complete.** Status: done. |
