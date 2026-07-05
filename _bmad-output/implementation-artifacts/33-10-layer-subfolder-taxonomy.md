# Story 33.10: Layer Sub-Folder Taxonomy - Exception/, Command/, Query/ (api/)

Status: ready-for-dev

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

- [ ] Task 1: Convention + audit worklist (AC: 1)
  - [ ] 1.1 Update `api/CLAUDE.md`: CQRS table rows gain "Lives in" = `Application/Command/` / `Application/Query/`; add the taxonomy paragraph (incl. the stays-flat rule for ports/helpers and the Domain-stays-flat rule); note `Domain/Exception/` + `Application/Exception/`.
  - [ ] 1.2 Produce `33-10-audit-worklist.md`: per context, classify every flat Application file (Command / Query+DTO / Exception / stays-flat-with-one-line-reason) and list the 8 exceptions with their target. Classification heuristics: return type void + repository writes = Command; returns DTO/array, name `*Query|*Catalog|*View|*Lookup|*Status` = Query; `extends \RuntimeException` = Exception; interfaces other than `*QueryInterface` = flat; anything ambiguous gets READ before classification (no name-only guessing for services).
  - [ ] 1.3 Commit convention + worklist before any src/ change.
- [ ] Task 2: Validator rules + allowlist (AC: 2)
  - [ ] 2.1 Rules: `*Exception.php` must live under `Domain/Exception/` or `Application/Exception/` or `Infrastructure/` (infra exceptions stay put per taxonomy); `*QueryInterface.php` must live under `Application/Query/` (update the 33.5 rule); both gated by `UNMIGRATED_TAXONOMY_CONTEXTS`.
  - [ ] 2.2 Allowlist seeded with ALL 17 non-Legal contexts + comment; tests: violation in a migrated context detected, allowlisted context passes, migrated-clean context passes.
  - [ ] 2.3 Gates green with the full allowlist (rules active but everything exempt - proves wiring before any move).
- [ ] Task 3: Batch 1 - pilots Identity + GameSelection (AC: 3, 4) - moves per the worklist map, allowlist shrink, full verification battery; record per-batch stats (files moved, services.yaml lines touched).
- [ ] Task 4: Batch 2 - Community, WeeklyRuns, Membership, Registrations, CatalogSync, Events (AC: 3, 4).
- [ ] Task 5: Batch 3 - the 8 small contexts (AC: 3, 4).
- [ ] Task 6: Gates + PR(s) + story record (AC: 5) - PR to `develop` (single PR with per-batch commits, or split per batch if review size demands); merge on green CI (standing authorization); `Sessions` migration recorded as the TODO epic-32 follow-up.

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

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date | Change |
|------|--------|
| 2026-07-05 | Story created from the in-session convention decision (post-epic-33 extension approved by Jean). Scope measured (~240 flat Application files, ~150-190 expected moves + 8 exceptions), 3 migration batches + Sessions deferral, decreasing-allowlist enforcement design, blast radius inherited from 33.5 but lighter (messenger untouched). Status: ready-for-dev. |
