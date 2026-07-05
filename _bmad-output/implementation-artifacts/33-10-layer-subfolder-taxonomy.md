# Story 33.10: Layer Sub-Folder Taxonomy - Exception/, Command/, Query/ (api/)

Status: ready-for-review

## Story

As a developer navigating the api's bounded contexts,
I want each layer's classes organised into kind sub-folders (`Domain/Exception/`, `Application/Command/`, `Application/Query/`, `Application/Exception/`) per the convention Jean validated in-session,
so that large Application folders (Community 45 flat files, Identity 36, GameSelection 31) become navigable and the folder structure materialises the CQRS taxonomy the naming convention already encodes - with zero behaviour change.

## Context - this is a NEW convention, not a leftover

Story 33.5 tidied placement BY LAYER and normalised the only two sub-namespaces the convention prescribed (`Application/Message/` + `Handler/`); its worklist item C7 explicitly recorded that everything else stays flat because `api/CLAUDE.md` said so. This story CHANGES that convention. Decisions locked by Jean (2026-07-05):

- `Domain/Exception/` for domain exceptions; entities/VOs/enums/repository interfaces STAY FLAT in Domain (the readable core).
- `Application/Command/` (command services, `VerbNoun`, return void) + `Application/Query/` (query services, `NounContext`, + their read DTOs + `{Name}QueryInterface`) + `Application/Exception/` (application exceptions, by symmetry). `Message/`, `Handler/`, and existing ad hoc dirs (`Email/`, `ScheduledTask/`) unchanged.
- Read DTOs live in `Query/` WITH their queries (a DTO lives and dies with its query) - no separate `Dto/`.
- Infrastructure and Presentation: NO new sub-folders (class prefixes `Doctrine*`/`Dbal*`/`Null*`/`Stub*` already encode kind; controllers stay flat, `Admin/` pattern stays).
- Uniform across contexts (no size threshold - mixed regimes are worse than either); `Sessions` LAST, only after Epic 32 merges.
- Ports/gateways interfaces that are neither command nor query (e.g. `RunnerGatewayInterface`, `AvatarResolverInterface`) and cross-cutting helpers (e.g. `ValidationErrors`, `SlugGenerator`, `PaginationHelper`, config resolvers, `ArchilanMailer`) STAY FLAT in Application - a small flat residue of shared surface is intended, not a violation.

## Acceptance Criteria

1. **AC1 - Convention written first.** `api/CLAUDE.md` is updated (CQRS table + bounded-context section) to prescribe the taxonomy above, in the same commit as the audit worklist and BEFORE any move. The worklist classifies EVERY flat Application file of every in-scope context (Command / Query / Exception / stays-flat-with-reason) plus the 8 exceptions - it IS the migration map and the story scope.
2. **AC2 - Validator enforces with a decreasing allowlist.** `DddArchitectureValidator` gains placement rules for the name-detectable kinds (`*Exception.php` under `{Layer}/Exception/`; `*QueryInterface.php` under `Application/Query/` - superseding 33.5's Application-level rule) plus a per-context migration allowlist (`UNMIGRATED_TAXONOMY_CONTEXTS`): a context still in the allowlist is exempt; migrating a context REMOVES it from the allowlist so regressions are impossible and progress is measurable. Unit tests per rule. `Sessions` stays allowlisted with the TODO epic-32 marker.
3. **AC3 - Per-context migration batches, each independently green.** Batch 1 (pilots): `Identity` + `GameSelection`. Batch 2: `Community`, `WeeklyRuns`, `Membership`, `Registrations`, `CatalogSync`, `Events`. Batch 3: `PersonalRuns`, `Payments`, `SessionConfig`, `Streaming`, `Shared`, `Content`, `Communications`, `Realtime`. Each batch = one commit (or PR if reviewed separately) with: moves + namespace/`use` updates + `services.yaml` FQCN updates + mirrored `tests/Unit/{Context}` import updates + allowlist shrink + full gate run. `Sessions` + `Legal` (empty) out of scope.
4. **AC4 - Blast-radius safety per batch.** `lint:container`, `cache:clear`, `doctrine:mapping:info` (45 entities - no Domain entity moves except exceptions, mapping untouched), `debug:messenger` before/after diff (should be IDENTICAL - messages/handlers do not move in this story), full suite on an isolated DB. Zero behaviour change.
5. **AC5 - All gates green at the end**; `pnpm gates` unaffected (zero frontend changes).

## Tasks / Subtasks

- [x] Task 1: Convention + audit worklist (AC: 1) → `api/CLAUDE.md` taxonomy + colocation/flat rules; worklist classified every flat Application file (3 parallel audits, 12 judgment calls resolved by 6 consistency rulings). Commit `4d8f66f`.
- [x] Task 2: Validator rules + allowlist (AC: 2) → `UNMIGRATED_TAXONOMY_CONTEXTS` + exception/query-interface placement rules, 4 new tests. Commit `94f90de` (proven green with full allowlist before any move).
- [x] Task 3: Batch 1 pilots Identity + GameSelection (AC: 3, 4) → 47 files moved; commit `7bb6fce`; full isolated suite green (1460 tests).
- [x] Task 4: Batch 2 Community/WeeklyRuns/Membership/Registrations/CatalogSync/Events (AC: 3, 4) → 103 files + Community Domain exception; part of `cb1bfa8`.
- [x] Task 5: Batch 3 the 8 small contexts (AC: 3, 4) → 22 moves + Shared/Communications/Realtime allowlist-only; part of `cb1bfa8`; allowlist now Sessions-only.
- [x] Task 6: Gates + PR + story record (AC: 5) → full battery green after each batch; PR opened; merge on green CI. Sessions migration recorded as TODO epic-32 follow-up.

## Dev Notes

### Measured scope (develop = 5d5930a, 2026-07-05)

Flat Application files: Community 45, Identity 36, GameSelection 31, WeeklyRuns 30, Membership 23, Registrations 16, CatalogSync 11, Events 10, PersonalRuns 9, Payments 8, SessionConfig 6, Streaming 5, Shared 4, Content 3, Communications 2, Realtime 1 → ~240 in scope (Sessions 29 deferred, Legal 0). A meaningful fraction stays flat (ports/helpers), so expect ~150-190 actual moves + the 8 exceptions.

### Blast radius (inherits the proven 33.5 machinery - but LIGHTER here)

- **messenger.yaml routing: UNTOUCHED** - messages and handlers do not move (already sub-namespaced). The `debug:messenger` before/after diff must be byte-identical; any difference = a mistake.
- `config/services.yaml`: the big surface - interface aliases and `arguments:` blocks keyed by Application FQCNs (e.g. all `{Name}QueryInterface: '@Dbal...'` lines move to `Application\Query\`). Literal PowerShell `.Replace()` per FQCN with throw-on-missing anchors (NEVER sed - see feedback memory), or the Edit tool.
- `src/Schedule.php`: imports Message classes only - untouched in principle; verify.
- Migrations coupling (33.5 finding): `Community\Domain\{DefaultAchievementDefinitions, AchievementMetricCatalog, AchievementOperator, AchievementRuleGroup}` are imported by merged migrations - they are Domain non-exceptions, they stay flat anyway. No conflict.
- `tests/Unit/{Context}` mirrors context only (flat) - import updates, no file moves. `tests/Functional` - import updates.
- Cross-context imports: many contexts import other contexts' Application classes (~270 imports mapped in 33.5) - the FQCN replaces must sweep ALL of src/ + tests/, not just the migrating context.

### Execution notes

- Same-namespace references become cross-namespace after moves (the 33.5 lesson): after each batch run phpstan FIRST to enumerate missing `use` statements, fix, then cs-fixer fix, then the rest of the battery.
- PhpStorm Move is a valid alternative for the mechanical part if Jean prefers driving; the verification battery is identical either way (agreed in-session).
- Per-batch full-suite runs on an isolated DB (`api/scripts/test-isolated.sh story3310`).
- The story is L: if session limits bite, each batch is a safe stopping point (allowlist keeps gates green between batches).

### References

- In-session decision record (Jean, 2026-07-05): taxonomy table + the two tie-breaks (DTOs in Query/, Application/Exception/ yes).
- 33.5 worklist C7 (the convention this story supersedes) + its blast-radius sections; 33.5/33.9 dev records for the Windows-execution lessons.
- `api/src/Shared/Application/DddArchitectureValidator.php` - rules + allowlist patterns from 33.5.

## Dev Agent Record

### Agent Model Used

Claude Fable 5 (batch 1) + Opus 4.8 1M (batches 2-3, finalisation); 3 parallel classification agents.

### Debug Log References

- Classification: 3 parallel audits read every flat Application file; 12 UNSURE resolved by 6 consistency rulings (worklist section "Consistency rulings").
- Two automation bugs found and fixed mid-run (both are the recurring Windows/refactor traps):
  1. PowerShell `-match` is case-insensitive → the use-resolver added `use ...\Command\ConfirmEmail` to `User.php` because the docblock/method `confirmEmail()` matched `ConfirmEmail`. Fixed with `-cmatch`/`-cnotmatch`. Residual docblock-only mentions (class name cited in a comment) still need a manual check per batch - caught 2 (`User`, `SessionConfigOverride`).
  2. Naive `.Replace()` on FQCNs is prefix-unsafe: moving `ActivateMembership` corrupted the FLAT `ActivateMembershipInterface`. Fixed with a `(?![A-Za-z0-9_])` word-boundary lookahead. `git reset --hard` + redo was cleaner than patching in place.
- `config/reference.php` (gitignored, Flex-regenerated) excluded from both scripts to avoid polluting it.
- Two validator test fixtures repointed from Events (now migrated) to Sessions (frozen) so the "unmigrated context" cases stay valid as the allowlist shrinks.
- Verification per batch: phpstan (symfony/doctrine ext), cs-fixer, `app:architecture:ddd`, `lint:container`, `debug:messenger` (135 lines, unchanged - no message/handler moves), unit 562, full isolated suite (1460 tests) - green each time.

### Completion Notes List

- ~150 files relocated across 16 contexts into `Application/Command|Query|Exception` + `Domain/Exception`; ~65 FLAT files (ports, helpers, mixed read/write facades, config holders) deliberately left in place per the taxonomy.
- `DddArchitectureValidator::UNMIGRATED_TAXONOMY_CONTEXTS` shrank from 17 → `['Sessions']`; the taxonomy is now enforced for every context except the Epic-32-frozen Sessions.
- Zero behaviour change: no message routing change (messenger untouched), no schema change, no controller/route change; the full suite is the contract.
- Reusable tooling left in the session scratchpad: `migrate-context.ps1` (word-boundary-safe move+rewrite) and `fix-uses.ps1` (case-sensitive same-namespace→cross-namespace use-resolver) - directly applicable to the deferred Sessions migration.

### File List

- Convention: `api/CLAUDE.md` (taxonomy + colocation/flat rules)
- Validator: `api/src/Shared/Application/DddArchitectureValidator.php` (allowlist + placement rules), `api/tests/Unit/DddArchitectureValidatorTest.php` (4 new tests + 2 fixtures repointed)
- Worklist: `_bmad-output/implementation-artifacts/33-10-audit-worklist.md`
- ~150 moved files across Identity, GameSelection, Community, WeeklyRuns, Membership, Registrations, CatalogSync, Events, PersonalRuns, Payments, SessionConfig, Streaming, Content (+ Community Domain exception); plus their `use`-updated referencers across src/tests and `config/services.yaml`. Full list in git (commits `7bb6fce`, `cb1bfa8`).

## Change Log

| Date | Change |
|------|--------|
| 2026-07-05 | Story created from the in-session convention decision (post-epic-33 extension approved by Jean). Scope measured (~240 flat Application files, ~150-190 expected moves + 8 exceptions), 3 migration batches + Sessions deferral, decreasing-allowlist enforcement design, blast radius inherited from 33.5 but lighter (messenger untouched). Status: ready-for-dev. |
| 2026-07-05 | Executed in 3 batches (Identity+GameSelection; the 6 mid contexts; the 8 small): ~150 files moved into Command/Query/Exception, allowlist shrunk 17→Sessions-only, 2 automation bugs (case-insensitive match, FQCN prefix collision) found and fixed, 2 docblock false-positives + 2 validator fixtures corrected. All gates green after each batch; full isolated suite 1460 tests. Status → ready-for-review. |
