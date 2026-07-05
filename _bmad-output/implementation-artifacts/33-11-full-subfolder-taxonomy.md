# Story 33.11: Full Sub-Folder Taxonomy - No Flat Files in Any Layer (api/)

Status: ready-for-dev

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

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date | Change |
|------|--------|
| 2026-07-05 | Story created from Jean's "no flat files in any layer" directive (extends 33.10). Full per-layer taxonomy confirmed (Domain/Entity + doctrine.yaml; Presentation/Controller uniform); ~378 moves in 4 layer batches; migration carve-out for 4 Community\Domain classes; Sessions frozen. Status: ready-for-dev. |
