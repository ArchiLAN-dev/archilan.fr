# Story 33.11: Full Sub-Folder Taxonomy - No Flat Files in Any Layer (api/)

Status: ready-for-review

## Story

As a developer navigating the api,
I want every class sorted into a kind sub-folder within its layer (no `.php` file sits directly in `Domain/`, `Application/`, `Infrastructure/` or `Presentation/`),
so that each folder communicates the exact kind of what it holds, uniformly across all contexts - with zero behaviour change.

## Context

33.10 introduced `Application/{Command,Query,Service,Exception}` + `Domain/Exception`. Jean then
decided (in-session) to extend the rule to ALL layers: nothing stays flat. Confirmed choices:
`Domain/Entity/` with the doctrine.yaml mapping updated; `Presentation/Controller/` uniform.
Sessions stays frozen (Epic 32). ~378 flat files in scope (Domain 120, Application 42,
Infrastructure 121, Presentation 95, minus Sessions).

## Target taxonomy (confirmed)

**Domain/**: `Entity/` (`#[ORM\Entity]`), `ValueObject/` (final readonly, no ORM), `Enum/`,
`Repository/` (`*RepositoryInterface`), `Service/` (pure domain logic: resolvers, definition
catalogs), `Exception/` (done).
**Application/**: existing `Command/ Query/ Service/ Exception/ Message/ Handler/ Email/` +
`Port/` (infra-facing interfaces, gateways, `Notifier`) + `Support/` (helpers, factories,
crypto, resolvers, normalizers, builders, providers, config holders, free DTOs).
**Infrastructure/**: `Doctrine/` (`Doctrine*Repository`), `Dbal/` (`Dbal*Query`), `Http/`
(existing - clients, `ApiAccessGuard`), `Console/` (existing), `Double/` (`Null*`/`Stub*`/`Spy*`,
`when@test`), plus a home for residual gateways/clients (`Http/` or a `Gateway/` if non-HTTP).
**Presentation/**: `Controller/` (all controllers; keep `Controller/Admin/` where `Admin/`
exists), `Command/` (console, existing), plus a home for the residual traits/webhook DTOs
(`Controller/Support/` or `Http/`).

## Acceptance Criteria

1. **AC1 - Convention + per-layer worklist committed first.** `api/CLAUDE.md` prescribes the full
   taxonomy; a worklist classifies every flat file per layer/context (name+content heuristics),
   before any move.
2. **AC2 - Validator enforces every name-detectable kind, decreasing allowlist.** New rules:
   `*RepositoryInterface`→`Domain/Repository/`, entities (`#[ORM\Entity]`)→`Domain/Entity/`,
   `*Controller`→`Presentation/Controller/`, `Doctrine*`→`Infrastructure/Doctrine/`,
   `Dbal*`→`Infrastructure/Dbal/`, `Null*`/`Stub*`/`Spy*`→`Infrastructure/Double/`, plus a
   catch-all "no .php directly in a layer folder" rule. Gated by `UNMIGRATED_FULL_TAXONOMY_CONTEXTS`
   (or reuse/extend the 33.10 allowlist). Content-undetectable kinds (VO vs Support, Port vs
   Service) are convention-only (documented, not gated), like `Application/Service/`.
3. **AC3 - doctrine.yaml + security.yaml updated.** All 13 entity mappings move to
   `prefix: App\{Ctx}\Domain\Entity` + `dir: .../Domain/Entity`; `security.yaml` user provider
   FQCN updated. `doctrine:mapping:info` shows all 45 entities discovered.
4. **AC4 - Migration carve-out honored.** The 4 `Community\Domain` classes imported by MERGED
   migrations (`DefaultAchievementDefinitions`, `AchievementMetricCatalog`, `AchievementOperator`,
   `AchievementRuleGroup`) KEEP their current FQCN (merged migrations are immutable). Documented
   exception; if the "no flat" catch-all would flag them, allowlist those 4 paths with a
   `migration-pinned` comment.
5. **AC5 - Per-layer/context batches, each green.** Ordered Presentation → Infrastructure →
   Application → Domain (Domain last: doctrine.yaml risk). Each batch: moves + namespace/use +
   services.yaml/doctrine.yaml/security.yaml + mirrored test moves + `lint:container` +
   `cache:clear` + `doctrine:mapping:info` + `debug:messenger` (unchanged) + full isolated suite.
6. **AC6 - All gates green; zero behaviour change; Sessions untouched.**

## Tasks / Subtasks

- [ ] Task 1: Convention (`api/CLAUDE.md`) + per-layer worklist committed (AC: 1)
- [ ] Task 2: Validator rules + allowlist + tests, green with full allowlist (AC: 2)
- [ ] Task 3: Batch Presentation - `Controller/` (+Admin) + residual (AC: 5)
- [ ] Task 4: Batch Infrastructure - `Doctrine/ Dbal/ Double/` (+Http/Console exist) (AC: 5)
- [ ] Task 5: Batch Application - `Port/ Support/` (AC: 5)
- [ ] Task 6: Batch Domain - `Entity/ ValueObject/ Enum/ Repository/ Service/` + doctrine.yaml + security.yaml + migration carve-out (AC: 3, 4, 5)
- [ ] Task 7: Final gates + PR + story record (AC: 6)

## Dev Notes

- Reuse the 33.10 tooling: `migrate-context.ps1` (word-boundary-safe move+FQCN rewrite, extend the
  sub-folder set) and `fix-uses.ps1` (case-sensitive same-namespace→cross-namespace resolver).
  Windows lessons: PowerShell `.NET` CWD via `[Environment]::CurrentDirectory`; `-cmatch` not
  `-match`; word-boundary lookahead on FQCN replace; watch docblock false-positives in Domain;
  exclude gitignored `config/reference.php`.
- **doctrine.yaml is the load-bearing change** (13 mappings). Do the Domain batch LAST, one context
  at a time, `doctrine:mapping:info` after each.
- **services.yaml Domain excludes** are path globs (`../src/{Ctx}/Domain/`) - they still cover
  `Domain/Entity/` etc. recursively, no change needed there; but explicit service FQCNs for moved
  Infrastructure/Application classes DO change.
- **messenger.yaml untouched** (messages/handlers already sub-namespaced) - `debug:messenger` diff
  must stay identical.
- Migration carve-out (AC4): 4 Community\Domain classes stay put; verify no OTHER merged-migration
  FQCN coupling before moving any Domain class (`grep -r "use App\\.*\\Domain" migrations/`).
- Presentation/Controller redundancy accepted by Jean for uniformity.

## Dev Agent Record

### Agent Model Used

Claude Opus 4.8 (1M) + 3 parallel classification agents (Domain kinds).

### Debug Log References

- Batches (each its own commit, full isolated suite green after each): Presentation `8aa85bc`
  (95 controllers + 19 admin), Infrastructure `ce4acaf` (121), Application `47423bf` (42),
  Domain `82f3518` (116 + doctrine.yaml + no-flat rule).
- The `fix-uses.ps1` resolver went through three corrections driven by real failures:
  (1) header-use-block insertion (never into a heredoc's inner `use`); (2) Domain-guard +
  cross-context Infrastructure/Presentation guard to kill docblock `{@see OtherCtxDbalX}`
  false-positives that cs-fixer keeps; (3) reverted comment-stripping once it hid legitimate
  docblock TYPE refs (`@param list<X>`) that genuinely need an import - the two guards handle
  the false-positives without hiding real usages.
- migrate-context/-layer/-domain FQCN rewrite is word-boundary-safe (`ActivateMembership` never
  corrupts `ActivateMembershipInterface`).
- Doctrine mapping: prefix+dir moved to `Domain/Entity`; two entity-less mapped contexts
  reverted (Streaming: only VOs; Sessions: frozen). `doctrine:mapping:info` = 45 entities.
- Migration carve-out: `grep` confirmed exactly 4 `Community\Domain` classes are imported by
  merged migrations; they stay flat (FQCN preserved) and `doctrine:migrations:list` loads.
- Two persistent Domain docblock false-positives (`User` cites `ChangeUserSlug`,
  `SessionConfigOverride` cites `SessionConfigResolver`) handled by the Domain guard.

### Completion Notes List

- ~378 files sorted so no `.php` sits directly in any layer (4 migration-pinned carve-outs excepted).
  Domain: `Entity/ ValueObject/ Enum/ Repository/ Service/ Exception/`. Application: +`Port/ Support/`.
  Infrastructure: `Doctrine/ Dbal/ Http/ Console/ Double/ Exception/ Adapter/`. Presentation:
  `Controller/(Admin/) Command/ Support/ Request/`.
- Validator gains `validateNoFlatLayerFiles` (gated by `UNMIGRATED_TAXONOMY_CONTEXTS`, carve-out
  allowlist) + updated doctrine-prefix rule; name-undetectable splits (VO vs Support, Port vs
  Service) stay documented convention. Sessions frozen (Epic 32).
- Zero behaviour change: messenger routing untouched, 45 entities mapped, all suites green.

### File List

- `api/CLAUDE.md` (full taxonomy), `api/config/packages/doctrine.yaml` (Domain/Entity prefixes),
  `api/config/packages/security.yaml` (User FQCN), `api/config/services.yaml` (FQCN rewrites)
- `api/src/Shared/Application/Support/DddArchitectureValidator.php` (no-flat rule + carve-out +
  doctrine-prefix update), `api/tests/Unit/DddArchitectureValidatorTest.php` (3 new tests + fixtures)
- ~378 moved class files across all contexts (except Sessions) + their `use`-updated referencers
  and mirrored tests - full list in git (commits `8aa85bc`, `ce4acaf`, `47423bf`, `82f3518`).

## Change Log

| Date | Change |
|------|--------|
| 2026-07-05 | Story created from Jean's "no flat files in any layer" directive (extends 33.10). Full per-layer taxonomy confirmed (Domain/Entity + doctrine.yaml; Presentation/Controller uniform); ~378 moves in 4 layer batches; migration carve-out for 4 Community\Domain classes; Sessions frozen. Status: ready-for-dev. |
| 2026-07-06 | Executed in 4 layer batches (Presentation, Infrastructure, Application, Domain), each committed and green on the full isolated suite (1463 tests). doctrine.yaml moved to Domain/Entity (45 entities mapped), migration carve-out honored, no-flat validator rule added. Resolver hardened through 3 real-failure-driven fixes. Status: ready-for-review. |
