# Story 35.21: Typed read-view record - Membership admin read-model (api/)

Status: done

## Story

As a maintainer closing out epic 35 Stage 2, I want the Membership admin read-model
(`AdminMembershipListQuery` search/findById/findLatestByUserId) to return a `final readonly` `MembershipView`
record instead of DBAL `array<string, mixed>` rows, so that `AdminEditMembership::edit` - which delegates its
return to `findById` - returns the record and drops the last `COMMAND_ARRAY_RETURN_EXEMPT` allowlist entry, with
every admin membership HTTP body unchanged.

## Context

Epic 35, Stage 2 follow-up surfaced by 35.20's validator rule. `AdminEditMembership::edit` returned
`array<string,mixed>|null` by delegating to `AdminMembershipListQuery::findById` - a DBAL 10-field row (with
joined `email`/`displayName` from user + community_profile). 35.20 allowlisted it (`COMMAND_ARRAY_RETURN_EXEMPT`)
and tracked it here. Same pattern as 35.14/35.15 (Content/Events admin read-models): type the query's read shape
into a shared record, and the delegating command is typed for free.

## Acceptance Criteria

1. **AC1 - Record + mapper.** Add `MembershipView` (`final readonly`, the 10 DBAL columns in SELECT order: id,
   userId, email, ?displayName, status, startedAt, expiresAt, source, ?helloassoOrderId, ?adminNote) with a
   `fromRow(array<string,mixed>): self` factory that narrows each `mixed` column and passes it through unchanged
   (byte-identical JSON), in `Membership/Application/Query/`.
2. **AC2 - Query typed.** `AdminMembershipListQueryInterface` + facade `AdminMembershipListQuery`: `findById`
   and `findLatestByUserId` return `?MembershipView`; `search` returns `{data: list<MembershipView>, meta}`.
   `DbalAdminMembershipListQuery` maps its rows via `MembershipView::fromRow` (`array_map` for search).
3. **AC3 - Command typed.** `AdminEditMembership::edit` returns `?MembershipView`.
4. **AC4 - Exemption removed.** `DddArchitectureValidator::COMMAND_ARRAY_RETURN_EXEMPT` + its `in_array` check
   deleted (replaced by a GONE comment, the codebase convention for an emptied allowlist);
   `validateCommandArrayReturns` now holds for every command with no escape hatch. `api/CLAUDE.md` AC-A3 drops
   the exemption sentence + the `COMMAND_ARRAY_RETURN_EXEMPT` citation (so `StandardsDocsMatchToolingTest` stays
   green - no dangling symbol).
5. **AC5 - Byte-identical.** `AdminMembershipListController` (`new JsonResponse($result)`) and
   `AdminEditMembershipController` (`['data' => $result]`) serialize the record(s) to the former DBAL rows (props
   in SELECT order). No controller change.
6. **AC6 - Gates.** `composer gates` green (ddd 0 violations with the exemption gone). Full isolated suite green.

## Tasks / Subtasks

- [x] Task 1: `MembershipView` record + `fromRow` (AC: 1).
- [x] Task 2: type the interface + facade + DBAL impl (AC: 2).
- [x] Task 3: `AdminEditMembership::edit` returns the record (AC: 3).
- [x] Task 4: delete the exemption const + check; update AC-A3 (AC: 4).
- [x] Task 5: verify + ship (AC: 5, 6). PR to `develop`.

## Dev Notes

- **`fromRow` centralises the narrowing.** DBAL `fetchAssociative()` columns are `mixed`; a single factory
  narrows all ten (`is_string(...) ? ... : '' | null`) so the DBAL impl stays terse and every consumer gets the
  same typed shape. The narrowing passes the actual value through, so the JSON is byte-identical - the admin
  membership list/edit functional tests (which assert the decoded body) are the byte-identity net.
- **`search` stays an `array`, legitimately.** It returns `{data: list<MembershipView>, meta}` - a Query result,
  outside `validateCommandArrayReturns` (which gates `Application/Command/` only). Only the delegating *command*
  `AdminEditMembership` had to become non-array.
- **Emptied allowlist, deleted mechanism.** With `AdminEditMembership` typed, `COMMAND_ARRAY_RETURN_EXEMPT` had
  no entries; per the codebase convention (the deleted `*_EXEMPT_CONTEXTS`/allowlists) an empty allowlist is dead
  code, so the const + its check are removed with a GONE comment. AC-A3's citation of the symbol is dropped in
  the same commit so the doc-sync test never sees a dangling `DddArchitectureValidator::` reference.
- House rules: `final readonly`, strict types, phpstan max. `composer gates`.

### References

- Pattern source: 35.14 (`AdminPostView`), 35.15 (`AdminEventView`); the exemption added in 35.20.
- Convert: `src/Membership/Application/Query/{AdminMembershipListQueryInterface,AdminMembershipListQuery}.php`,
  `src/Membership/Infrastructure/Dbal/DbalAdminMembershipListQuery.php`,
  `src/Membership/Application/Command/AdminEditMembership.php`,
  `src/Shared/Application/Support/DddArchitectureValidator.php`, `api/CLAUDE.md`.
- New: `src/Membership/Application/Query/MembershipView.php`.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context)

### Completion Notes List

- `MembershipView` (10 fields) + `fromRow` narrowing factory in `Membership/Application/Query/`.
- `AdminMembershipListQueryInterface`/facade/`DbalAdminMembershipListQuery` return the record (`search` ->
  `{data: list<MembershipView>, meta}`); `AdminEditMembership::edit` -> `?MembershipView`.
- Both admin controllers serialize the record(s) byte-identically; no controller change. phpstan confirmed no
  consumer read the row keys.
- `COMMAND_ARRAY_RETURN_EXEMPT` const + check deleted (GONE comment); AC-A3 rewritten (no exemption, no dangling
  citation). `validateCommandArrayReturns` now has no escape hatch. `StandardsDocsMatchToolingTest` green.
- `composer gates` green: phpstan max 0, cs-fixer 0, ddd OK (rule enforced with no exemption), rector OK,
  phpunit green (full isolated suite).
- **Epic 35 Stage 2 fully closed** - every command returns void/record/enum, gated, no allowlist.

### File List

- `api/src/Membership/Application/Query/MembershipView.php` (new)
- `api/src/Membership/Application/Query/AdminMembershipListQueryInterface.php` (returns the record)
- `api/src/Membership/Application/Query/AdminMembershipListQuery.php` (returns the record)
- `api/src/Membership/Infrastructure/Dbal/DbalAdminMembershipListQuery.php` (maps rows via `fromRow`)
- `api/src/Membership/Application/Command/AdminEditMembership.php` (returns `?MembershipView`)
- `api/src/Shared/Application/Support/DddArchitectureValidator.php` (exemption removed)
- `api/CLAUDE.md` (AC-A3: exemption + citation dropped)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-18 | Story created + implemented (epic 35 Stage 2, follow-up to 35.20). `MembershipView` read-view record; Membership admin query + `AdminEditMembership` typed; `COMMAND_ARRAY_RETURN_EXEMPT` removed. `composer gates` green. **Stage 2 fully closed (no allowlist).** Status: done. |
