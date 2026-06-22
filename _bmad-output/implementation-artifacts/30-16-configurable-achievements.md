# Story 30.16: Configurable achievements (DB-backed + composable rule engine + admin form)

Status: done

## Story

As an **admin**,
I want to create and edit achievements from the back-office - name, description, an **arbitrarily
composable set of unlock rules**, and an active flag - instead of editing code,
so that the catalog can evolve (and get as rich as we want) without a deploy. Deps: 30.4 (achievement
engine), 30.13 (admin patterns).

Move the code-defined `AchievementCatalog` into a database table managed by an admin form, and replace the
single `metric >= threshold` check with a **recursive boolean rule tree** evaluated by the existing
deterministic, monotonic recompute. The set of evaluable **facts (metrics)** is a pluggable registry so we
can keep adding new combinable facts cheaply.

## Design - the rule engine (the heart of this story)

**Rule tree (recursive, JSON-stored).** A definition owns one root rule. A rule node is either:
- a **group**: `{ op: "all" | "any" | "none", rules: RuleNode[] }` - AND / OR / NOR, nestable to any depth;
- a **condition (leaf)**: `{ fact: string, operator: ">=" | ">" | "=" | "!=" | "<=" | "<" | "between", value: int, value2?: int }`.

Evaluation: a sealed `AchievementRule` interface (`Group`, `Condition`) with `matches(MetricBag): bool`,
hydrated from/serialized to the JSON column. `none` = "none of the children match" (enables NOT-style
combinations). Arbitrary nesting → maximum composability (e.g. *(goals ≥ 10 AND distinctGames ≥ 5) OR
events ≥ 3*).

**Fact registry (extensible).** Facts are not hard-wired into the engine. A `MetricBag` (string → int,
defaulting unknown facts to 0) is assembled by a `MetricBagBuilder` from a tagged set of
`AchievementMetricProviderInterface` implementations, each contributing one or more facts for a user. An
`AchievementMetricCatalog` exposes the available fact keys + labels + value kind to the admin form
(dropdown) and validation. **Adding a new combinable fact = add one provider + one catalog entry**, no
engine/form change.

- **Seed facts (from existing read models):** `runs`, `goals`, `checks`, `items`, `distinctGames`
  (the current 5, via `AchievementMetrics`/PlayerStats).
- **Planned-cheap facts to include if data is already there:** `eventsAttended` (confirmed registrations),
  `goalCompletionRatePct`, `accountAgeDays` / `memberSinceDays`, `weeklyRunsParticipated` (Epic 16),
  `kudosReceived`, `friendsCount`. Each is a small provider; pick the subset whose data is a straight read.

## Acceptance Criteria

1. New `community_achievement_definition` table: `key` (immutable, unique), `name`, `description`,
   `rule` (JSON rule tree), `active`, `position`, timestamps. Migration **seeds the 9 current entries**
   (each a one-condition tree, `>=`) so nothing is lost; parity test proves identical grant outcomes.
2. Recursive rule engine: `AchievementRule` (sealed: `AchievementRuleGroup` with `all|any|none`,
   `AchievementRuleCondition` with the 7 operators), pure `matches(MetricBag)`, JSON (de)serialization,
   nestable to any depth.
3. Extensible fact registry: `MetricBag` + `MetricBagBuilder` over tagged `AchievementMetricProviderInterface`s;
   `AchievementMetricCatalog` (fact key → label/kind) drives the admin dropdown and server validation.
   Ship the 5 seed facts; add the cheap extra facts whose data is a direct read (decide per-fact at impl).
4. `RecomputeAchievements` reads **active** definitions from a repository and evaluates the rule tree.
   Stays **monotonic** (only adds grants; a loosened/new rule retroactively grants on the next pass; never
   revokes - even a `none`/`<` rule that flips false later keeps the past grant, Steam-like, documented).
   Deactivating a definition stops future grants, keeps existing ones.
5. Admin endpoints (admin-only): list, create, update, toggle-active, reorder. Validation: unique non-empty
   immutable `key`; rule tree well-formed (every group `op ∈ {all,any,none}` with ≥1 child; every condition
   `fact` in the catalog, `operator` in the set, integer `value`(s), `value2 > value` for `between`); depth
   ≤ a sane cap; 422 on invalid.
6. Frontend `/admin/achievements`: list (name, rule summary, active, position) + create/edit form with a
   **recursive rule builder** (add condition / add nested group, choose AND/OR/NONE, fact + operator +
   value rows), active toggle, reorder. Nav entry.
7. Public profile + directory unaffected (they read grants); `community:achievements:recompute` works off
   the DB.
8. Gates green: phpstan / php-cs-fixer / phpunit (0 notices) / `app:architecture:ddd`; typecheck / lint /
   build / jest.

## Tasks / Subtasks

- [x] **api/ Domain:** `AchievementRule` (sealed) + `AchievementRuleGroup` / `AchievementRuleCondition`
      (pure `matches`, from/to array); operator enum; `MetricBag`; `AchievementMetricCatalog` (fact keys +
      labels); `AchievementDefinition` (hydrated VO holding the root rule + meta);
      `AchievementDefinitionRepositoryInterface`.
- [x] **api/ Application:** `AchievementMetricProviderInterface` (+ tag) and a `MetricBagBuilder`; refactor
      the current 5 metrics into the first provider (`StatsMetricProvider` over PlayerStats/history);
      `RecomputeAchievements` → repo + `MetricBagBuilder` + rule eval; `AdminAchievementService`
      (create/update/toggle/reorder + rule validation).
- [x] **api/ Migration:** `community_achievement_definition`; `postUp` seeds the 9 entries as one-condition
      trees.
- [x] **api/ Infrastructure:** Doctrine repo (rule JSON ↔ tree); providers registered via DI tag.
- [x] **api/ Presentation:** `AdminAchievementController`.
- [x] **api/ tests:** unit - rule eval (each operator; nested all/any/none; depth; JSON round-trip),
      `MetricBagBuilder` composition, `AdminAchievementService`; functional - `AdminAchievementTest`
      (CRUD + 422s + admin-only), **seed-parity** test (DB seed reproduces the old grants exactly).
- [x] **frontend:** `admin-achievements-api.ts` + `/admin/achievements` (recursive rule builder) + nav.
- [x] **Gates** - all green.

### Implementation notes (2026-06-18)
- **Reverses the epic's "catalog is code-defined" decision (§C/§E.1)** at Jean's request: the catalogue now
  lives in `community_achievement_definition`, seeded from `DefaultAchievementDefinitions` (the former
  hard-coded 9). Engine stays pure (`AchievementRule::matches(MetricBag)`); facts come from DI-tagged
  `AchievementMetricProviderInterface`s composed by `MetricBagBuilder`.
- Shipped the 5 seed facts only (`StatsMetricProvider`); the planned-cheap extra facts are left as
  follow-up providers (zero engine/form change to add later).
- Public profile shows active definitions **plus** any the viewed user already earned (deactivating keeps
  past grants visible - monotonic). Gates: phpstan/cs-fixer/phpunit (1231)/ddd green; typecheck/lint/build green.

## Dev Notes

### Reuse, don't reinvent
- The current 5 metrics become the first `AchievementMetricProvider` (wrapping today's
  `AchievementMetrics`/`DbalPlayerStatsQuery` + distinct-games), so existing behaviour is preserved and the
  registry has a reference implementation.
- Admin CRUD + dashboard mirror the 30.13 patterns; rule JSON stored like showcaseLayout / notification
  payload (JSON column), hydrated to VOs in Infrastructure - never raw arrays in Domain eval.

### Architecture guardrails
- Engine is **pure** (Domain): `matches(MetricBag)` only; no DB/clock/registry inside. Facts are injected
  via the bag, built in Application from providers (each provider may read its own context's query). New
  fact = new provider, zero engine/form churn - this is the "maximally combinable" lever.
- **Monotonic recompute** (epic §E.1) is preserved at the engine boundary: definitions/rules are data,
  grants are facts; recompute only *adds*. Operators like `<`, `!=`, `none` are allowed for composition but
  past grants are never revoked when a rule later evaluates false (document in the admin UI).
- `key` immutable after create; rule/name/description/active editable. Cap nesting depth (e.g. 5) to keep
  the builder + evaluation bounded.

### Scope boundaries / deviations
- **Reverses the epic's "catalog is code-defined" decision (§C/§E.1)** at Jean's request - note in the
  epic changelog at implementation.
- New **fact kinds** still require a (small) code provider - the form composes over registered facts, it
  can't invent new data sources. Per-game / per-event parameterized facts (e.g. "completed game X") are a
  natural extension (a provider returning per-key facts) - list as a follow-up unless requested now.
- No per-achievement XP weight yet (flat `XP_PER_ACHIEVEMENT`); icons optional - defer unless asked.

### Project Structure Notes
- New api Domain: `AchievementRule`, `AchievementRuleGroup`, `AchievementRuleCondition`,
  `AchievementOperator`, `MetricBag`, `AchievementMetricCatalog`,
  `AchievementDefinitionRepositoryInterface`.
- New api Application: `AchievementMetricProviderInterface`, `MetricBagBuilder`, `StatsMetricProvider`
  (+ optional extra providers), `AdminAchievementService`.
- New api Infrastructure: `DoctrineAchievementDefinitionRepository`; migration `Version*`.
- Changed api: `RecomputeAchievements` (repo + bag + rule eval); retire `AchievementCatalog`;
  `AchievementDefinition` reshaped.
- New frontend: `features/admin/admin-achievements-api.ts` + dashboard with recursive rule builder,
  `app/(admin)/admin/achievements/page.tsx`, admin-shell nav entry.
