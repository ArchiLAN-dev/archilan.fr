# Epic 35 - Strict CQRS Write Path (idea, not planned)

Status: idea (recorded 2026-07-11, decision from story 33.17's AC-A3 reconciliation)
Date: 2026-07-11

## Origin

Story 33.17 surfaced that `api/CLAUDE.md` AC-A3 ("Command services return void") was contradicted by
the established convention: ~60 public methods across the 72 `Application/Command/` classes return
outcome arrays (`['found' => ..., 'errors' => ..., 'data' => ...]`) consumed by controllers to build
HTTP responses. Decision (Jean, 2026-07-11): amend AC-A3 to describe the current contract and enforce
the real invariant (no Doctrine entity returned from Application - validator rule since 33.17); the
move to strict CQRS is recorded here as its own future epic, to be taken up for real reasons (need to
scale/evolve read and write models independently), not doctrinal conformity.

## Goal (if/when planned)

Move the api write path from outcome-array returns to the strict form, in independently shippable
stages:

1. **Typed failures.** Replace outcome discriminants (`'outcome' => 'not_found'`, `'errors' => [...]`)
   with typed Application exceptions (`*NotFoundException`, `ValidationFailedException` carrying the
   field map), mapped centrally to HTTP status codes (kernel listener). Mechanical, context by context.
2. **Typed results.** Replace remaining associative-array returns with `final readonly` result records
   (ID/version/acknowledgement only - no read payloads). Kills the array-shapes at the
   Application/Presentation boundary.
3. **Command/query split in controllers.** Command returns void/minimal record; the response body is
   built by a separate query call (read-your-writes on the same DB). This is the substantive chantier:
   one extra read per write, full decoupling of write and read models; prerequisite for event sourcing
   or a physically split read model if ever needed.

## Constraints

- Stage 3 only pays for itself if a real driver appears (read-model scaling, eventual consistency,
  event sourcing). Stages 1-2 are worth doing on code-quality grounds alone and can land without 3.
- Epic-sized: touches all 72 command services, their controllers and tests. Per-context stories,
  gates green at every step, AC-P3/P4 (thin controllers) must keep holding.
- AC-A3 (as amended by 33.17) and the no-entity-return validator rule stay authoritative during any
  intermediate state; the validator rule set tightens further as stages land (e.g. stage 2 enables a
  "command returns void or a record, never array" rule).
