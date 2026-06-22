# Story 30.32: Event-Goal Achievement Rules (Generic + Per-Event)

## Story

**As an** admin,
**I want** to create achievements based on **reaching a goal in an event** - generic ("a atteint un
objectif lors d'un événement ArchiLAN") or for a **specific event** ("a atteint un objectif lors
d'ArchiLAN #3"),
**So that** event feats become recognisable badges on members' profiles.

## Context

The achievements engine (stories 30.4, 30.16, 30.26) is a **fact-based rule tree**: a `RuleGroup`
(`all`/`any`/`none`) of `RuleCondition`s `{fact, operator, value, value2?}`, evaluated against a per-user
`MetricBag` built from tagged `AchievementMetricProviderInterface` providers. The only provider today is
`StatsMetricProvider`. Recompute runs **after a session is archived** (run finish →
`RecomputeAchievementsForUserMessage`) and via the admin "recompute all" command.

Events live in the `Events`/`Registrations`/`Sessions` contexts; the Community engine has no access to
"did user reach a goal in event X". A new metric provider supplies those facts **without touching the
rule engine**. (Scope split from the original combined story; the profile/catalogue rework is story
30.31.)

**Decision - goal-scoped only:** we reward **reaching a goal in an event**, not mere registration. A goal
implies a *finished session*, which is exactly when recompute already fires - so **no new recompute
trigger is needed**. A pure "registered to an event" achievement would require recompute on registration
confirmation and carries little value; it is **out of scope**.

## Status

done

## Acceptance Criteria

**AC1 (facts):** A new `EventParticipationMetricProvider` (Community/Application, tagged
`community.achievement_metric_provider`) emits, for the user being recomputed:
- `eventsWithGoal` - count of **distinct events** where the user reached ≥1 goal (a `session_slot` for one
  of their registrations in that event has `goal_reached_at IS NOT NULL`, session `finished`);
- per-event facts `event_goal:{eventId}` = `1` for each such event.
Facts are **sparse** (only the user's own events), so the `MetricBag` stays small. Unknown facts read as
`0` (`MetricBag::get`), so a rule for an event the user never won simply doesn't match.

**AC2 (cross-context read, DDD-safe):** a new `EventParticipationQueryInterface` (Community/Application)
with a `DbalEventParticipationQuery` (Community/Infrastructure) reads the `registration` →
`session` (by `event_id`) → `session_slot` (`goal_reached_at`, `registration_id`) tables **directly by
name** - no `Events`/`Registrations`/`Sessions` domain imports - mirroring how `DbalPlayerStatsQuery`
reads cross-context tables. The `app:architecture:ddd` gate stays green.

**AC3 (scoped-fact validation - the integration crux):** the admin rule validation currently checks a
condition's `fact` against the static `AchievementMetricCatalog::facts()`. It must additionally accept the
**scoped family** `event_goal:{eventId}` **only when `{eventId}` is a real event**; an unknown/invalid
event id is rejected with a validation error. `AchievementRuleFactory` itself is unchanged (the fact is
still an opaque string to the engine).

**AC4 (admin authoring):** `AdminAchievementService::formOptions()` (and the admin dashboard
`admin-achievements-dashboard`) gains an **event scope**: building a condition, the admin can pick either
the generic fact `eventsWithGoal` or a specific event from a **`<select>` of selectable events**, which
stores the scoped fact `event_goal:{eventId}`. The selectable list is every **non-draft** event
(`published` / `in-progress` / `completed`), **newest first** - so a finished event like « ArchiLAN #3 »
is pickable (draft events are excluded). When **displaying** an existing rule, a scoped fact resolves back
to its event **title** (e.g. « Objectif atteint - ArchiLAN #3 »), never the raw `event_goal:{uuid}`. A
rule referencing a **deleted** event displays a clear « événement supprimé » and never matches (silently).

**AC5 (seed + recompute):** seed an active generic example `event_finisher` = `eventsWithGoal >= 1` in
`DefaultAchievementDefinitions`, so members who already won an event earn it on the next recompute. No new
trigger: the existing post-archive recompute (and the admin recompute-all command) rebuild the bag with
the new facts.

**AC6:** All quality gates pass (phpstan, php-cs-fixer, phpunit, app:architecture:ddd; frontend
typecheck/lint/build/jest).

## Tasks / Subtasks

- [x] Task 1: API - `EventParticipationQueryInterface` + `DbalEventParticipationQuery`: method
  `eventIdsWithGoal(string $userId): list<string>` joining `registration` (status `reserved`,
  `submitted_at IS NOT NULL`) → `session` (`event_id`, status `finished`) → `session_slot`
  (`registration_id`, `goal_reached_at IS NOT NULL`).
- [x] Task 2: API - `EventParticipationMetricProvider` implementing `AchievementMetricProviderInterface`:
  emit `eventsWithGoal` (count) + `event_goal:{eventId}` (=1) per event. Register the tag if not
  autoconfigured.
- [x] Task 3: API - `AchievementMetricCatalog`: add `eventsWithGoal` (FR label). Extend the admin
  validation to accept the `event_goal:{eventId}` family against a real-event check; extend
  `formOptions()`/controller payload with the selectable-events list (id + title, non-draft, newest
  first) for the scope picker and for rule-display label resolution. Seed `event_finisher`.
- [x] Task 4: API tests (functional, full schema) -
  - a member with a **finished** session + a `goal_reached_at` slot tied to their **submitted**
    registration in event E gets `eventsWithGoal=1`, `event_goal:E=1`, and earns `event_finisher`;
  - a **cancelled** or **non-submitted** registration does **not** count;
  - a goal in a **different** event does **not** satisfy a rule scoped to event E;
  - the admin create/update **rejects** a `event_goal:{bogusId}` referencing no event;
  - a rule scoped to a **deleted** event no longer matches (no error).
- [x] Task 5: Frontend - admin dashboard event-scope selector: a `<select>` (generic `eventsWithGoal` vs a
  specific event from the non-draft list provided by `formOptions`); render an existing scoped condition as
  the event title; show « événement supprimé » fallback. Update `admin-achievements-api.ts` types/payload.
- [x] Task 6: Frontend tests (jest) - the scope selector emits the right fact key; scoped-fact display
  resolves to the event title.
- [x] Task 7: Quality gates.

## Dev Notes

### Engine stays fact-based (no breaking change)

Specific-event = a scoped fact key `event_goal:{eventId}` emitted only for events the user won. No
parameterized rule type, no `AchievementRuleFactory` change. The only place that learns about events is
**admin validation/display + the provider** - the evaluator still sees an opaque string fact.

### Recompute coverage is already correct

Because we scope to *goal reached* (which requires a finished session), the existing post-archive
recompute fires at exactly the right moment. This is why pure "registered" achievements are excluded -
they'd need a recompute-on-registration trigger we deliberately avoid here.

### Cross-context reads - precedent

`DbalPlayerStatsQuery` (Identity Infra) already reads `session_slot`/`registration`/`weekly_entries` by
table name across contexts. The new DBAL query follows that precedent; keep all event/session knowledge in
Infrastructure behind the Community Application interface.

### Dangling event references

A rule may outlive its event (admin deletes an event). The scoped fact then reads 0 → the rule never
matches (safe). The admin UI must surface this (« événement supprimé ») so authors aren't confused; no
data migration or cascade is attempted.

### Out of scope

- "Registered to an event" (no-goal) achievements - would need recompute on registration confirmation.
- Multi-event composite scopes beyond a single event per condition (admins can still compose with
  `any`/`all` groups over several single-event conditions).
- Backfill notifications for retroactively-granted event achievements (bulk recompute grants silently).
