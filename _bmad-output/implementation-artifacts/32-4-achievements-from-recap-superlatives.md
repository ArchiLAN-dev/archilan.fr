# Story 32.4: Achievements from recap superlatives

Status: review

## Story

As a player who earned a superlative in a public session recap,
I want that superlative to count as an achievement fact,
so that admins can create ArchiLAN achievements ("Le Parrain"...) that unlock from recap
superlatives - and my profile celebrates them.

Fourth and last story of Epic 32 - *Récap de partie*. api/ only. Per the epic: wire 32.1's
superlatives into the Epic 30.4 achievement engine via an `AchievementMetricProvider` over the
recap projection - **no rule-engine change**.

Depends on: 32.1 (persisted `session_recap` projection with slot-id-keyed superlatives), Epic 30
achievement engine (stories 30.16/30.26/30.32).

## Context

- The achievement engine is **fact-based and provider-extensible** (story 30.16): implementing
  `AchievementMetricProviderInterface` (`Community/Application/Port`, autoconfigure-tagged
  `community.achievement_metric_provider`) is all it takes for `MetricBagBuilder` to fold new facts
  into every evaluation. `RecomputeAchievements` is monotonic and idempotent.
- The admin achievement form lists facts from `AchievementMetricCatalog::facts()`
  (`AdminAchievementService` line ~63) and validates rule facts with
  `AchievementMetricCatalog::isValidFact` - **concrete catalog entries propagate to the admin UI
  and validation with zero extra code** (unlike the `event_goal:` prefix which needed a picker,
  story 30.32).
- Superlative keys are fixed in `RecapSuperlativesCalculator` (Sessions):
  `most_generous` ("Le Parrain"), `biggest_hub` ("Le Facteur"), `first_to_goal`
  ("Speedy Gonzales"), `longest_road` ("Le Seigneur des Anneaux").
- The projection's `superlatives` JSON entries carry `slotId` = `session_slot.slot_id`
  (reconciled by `BuildSessionRecapJobHandler`). Mapping a superlative to a user goes
  `session_recap.superlatives[].slotId` → `session_slot` (by `session_id` + `slot_id`) →
  `registration` → `user_id`. Cross-context reads by table name are the established Community
  pattern (`DbalEventParticipationQuery`, story 30.32).
- **Ordering bug to fix while here:** `SessionLifecycleManager::storeArchive` triggers the
  achievement recompute (story 30.26) and *then* dispatches `BuildSessionRecapJob` - both async.
  The recompute can therefore run before the projection exists, and nothing re-evaluates after
  the recap is built: superlative facts would stay invisible until an unrelated recompute. The
  recap build handler must itself trigger a recompute once the projection is persisted, through
  the existing `AchievementRecomputeTriggerInterface` port (`Sessions/Application/Port`).

### Architecture decisions (locked)

1. **Facts are concrete catalog entries, not a prefix.** `AchievementMetricCatalog` gains
   `FACT_SUPERLATIVES = 'superlatives'` (total won) plus four fixed keys
   `superlative:most_generous`, `superlative:biggest_hub`, `superlative:first_to_goal`,
   `superlative:longest_road`, each with a French label in `facts()` (mirroring the calculator's
   pop-culture names). `isValidFact` and the admin form then work untouched. Key strings are
   duplicated from `RecapSuperlativesCalculator` on purpose - Community must not import Sessions;
   the duplication is documented at both ends.
2. **`RecapSuperlativesQueryInterface`** (`Community/Application/Query`):
   `superlativeCountsFor(string $userId): array<string, int>` (superlative key => times won).
   DBAL implementation `DbalRecapSuperlativesQuery` (`Community/Infrastructure/Dbal`): join
   `session_recap` → `session` (status `finished`) → `event` (`is_public = true`) →
   `session_slot` → `registration` (`user_id`, status `reserved`, `submitted_at IS NOT NULL`),
   decode the superlatives JSON in PHP and count entries whose `slotId` equals that row's
   `slot_id`. The registration join naturally excludes personal/weekly runs; the event join
   mirrors the recap privacy model.
3. **`RecapSuperlativesMetricProvider`** (`Community/Application/Support`, implements the tagged
   interface): emits `superlatives` (sum) + one `superlative:{key}` fact per non-zero key.
4. **Recompute after recap build.** `BuildSessionRecapJobHandler` injects
   `AchievementRecomputeTriggerInterface` + `RegistrationRepositoryInterface` and, after
   persisting the projection, triggers `recomputeForUsers` for the session's participants
   (registration → `user_id`; a personal-run slot's registrationId is already the user id -
   mirror `SessionLifecycleManager::resolveParticipantUserIds`). The `storeArchive` trigger
   stays (it covers stats facts even when the recap build fails).
5. **No seeded achievement definition.** Definitions are DB-backed and admin-authored (Epic 30
   decision); Jean creates the actual "Le Parrain" achievement in the admin UI over the new
   facts. Also: `DefaultAchievementDefinitions` is part of the merged-migrations carve-out - do
   not touch it.

## Acceptance Criteria

1. **Catalog.** The five new facts exist with French labels in `AchievementMetricCatalog::facts()`;
   `isValidFact('superlative:most_generous')` is true; existing facts and the `event_goal:` prefix
   behavior are unchanged.
2. **Query + DBAL.** `RecapSuperlativesQueryInterface` + `DbalRecapSuperlativesQuery` per decision
   #2: counts only superlatives won by that user's slots, only in finished sessions of public
   events; a superlative of another slot, a private event, or a personal run contributes nothing.
   Defensive JSON handling (malformed/absent superlatives column contributes nothing, never throws).
3. **Provider.** `RecapSuperlativesMetricProvider` emits `superlatives` total + per-key facts,
   omitting zero-count keys (sparse, like the event_goal facts); with no superlatives it emits
   `['superlatives' => 0]` only.
4. **Recompute timing.** After `BuildSessionRecapJobHandler` persists (create or rebuild) a
   projection, it triggers `recomputeForUsers` with the session participants' user ids. No
   trigger when the build aborts early (missing session / not finished).
5. **Tests.** Unit: provider over a stubbed query (sparse emission, zero case); catalog validity.
   Functional: `DbalRecapSuperlativesQuery` with fixtures proving every exclusion in AC #2;
   `BuildSessionRecapJobHandlerTest` extended - trigger receives the participants after a
   successful build, nothing on early return.
6. **Gates green:** `composer gates` (phpstan max, cs-fixer, DDD validator, rector, full phpunit
   0 notices). Frontend untouched - `pnpm gates` not required, but the admin achievements form
   must keep working (it renders facts from the API - no frontend change expected).

## Tasks / Subtasks

- [x] **T1 - Catalog facts (AC #1).** Constants + `facts()` labels + unit test additions
  (existing catalog test class if any, else cover via provider test).
- [x] **T2 - Query interface + DBAL impl (AC #2).** Follow `DbalEventParticipationQuery`
  (cross-context by table name, narrow every fetched column). JSON decode with `json_decode(...,
  true)` + `is_array` guards.
- [x] **T3 - Provider (AC #3).** Tagged via the interface's `#[AutoconfigureTag]` - no
  services.yaml change expected (verify the other providers have no explicit wiring).
- [x] **T4 - Recompute after build (AC #4).** Inject the two extra dependencies into
  `BuildSessionRecapJobHandler`; resolve participants like
  `SessionLifecycleManager::resolveParticipantUserIds`; trigger after the save, with logging.
- [x] **T5 - Tests (AC #5).** TDD: write red tests first for T2 (functional) and T4 (handler),
  then implement. Run via `api/scripts/test-isolated.sh`.
- [x] **T6 - Gates (AC #6).** `composer gates` all green.

## Dev Notes

- **DDD:** query interface in `Community/Application/Query`, DBAL in
  `Community/Infrastructure/Dbal` (AC-A2 + feedback-ddd-application-layer); provider in
  `Application/Support`; no EntityManager/Connection outside Infrastructure; Sessions handler only
  uses its own ports (`AchievementRecomputeTriggerInterface` already exists there - do NOT import
  Community from Sessions).
- **phpstan max:** `fetchAllAssociative` rows are `array<string, mixed>` - `is_string`/`is_array`
  narrowing on every column; json_decode result narrowed before use.
- **cs-fixer:** Yoda, `declare(strict_types=1)`.
- The `MessengerAchievementRecomputeTrigger` adapter dispatches
  `RecomputeAchievementsForUserMessage` per user (async) - notifications on unlock come from
  `RecomputeAchievements` via the post-commit `Notifier` (story 30.26). Nothing to add there.
- **Handler test doubles:** check how `BuildSessionRecapJobHandlerTest` obtains the handler
  (container vs manual construction) - if manual, a small spy implementing the trigger interface
  in the test file is enough; if container, look for a `Double/` Null trigger to spy on or
  replace the service in `when@test`.
- Functional fixtures: reuse `EventRecapIndexEndpointTest` / `SessionRecapEndpointTest` recipes
  (public + private events, finished sessions, slots with `slot_id`, registrations, projections
  with superlatives arrays).
- Typography: no em-dashes anywhere.

### References

- [Source: _bmad-output/planning-artifacts/epics/epic-32-session-recap.md#Proposed stories] (32.4)
- [Source: api/src/Community/Application/Port/AchievementMetricProviderInterface.php] (tagged port)
- [Source: api/src/Community/Application/Support/EventParticipationMetricProvider.php] (provider model)
- [Source: api/src/Community/Infrastructure/Dbal/DbalEventParticipationQuery.php] (cross-context DBAL model)
- [Source: api/src/Community/Domain/AchievementMetricCatalog.php] (fact catalog)
- [Source: api/src/Community/Application/Command/RecomputeAchievements.php] (engine semantics)
- [Source: api/src/Sessions/Application/Handler/BuildSessionRecapJobHandler.php] (build flow to extend)
- [Source: api/src/Sessions/Application/Service/SessionLifecycleManager.php#storeArchive] (ordering issue + participant resolution)
- [Source: api/src/Sessions/Application/Support/RecapSuperlativesCalculator.php] (the four keys)

## Dev Agent Record

### Agent Model Used

claude-fable-5 (Claude Code)

### Debug Log References

### Completion Notes List

- **TDD:** all three test surfaces written first and confirmed red (unit provider errored on the
  missing classes; the extended handler test could not construct the new signature; the functional
  query test had no implementation), then implemented to green.
- **Catalog approach validated end to end:** the `superlative:{key}` facts are plain `facts()`
  entries, so `RecomputeAchievements` grants a definition built over
  `superlative:most_generous` with zero engine/admin changes
  (`RecapSuperlativeAchievementTest::testRecomputeGrantsAnAchievementDefinedOverASuperlativeFact`).
  `isValidFact('superlative:unknown')` stays false - the enumeration is closed, unlike
  `event_goal:`.
- **No services.yaml change:** Symfony auto-aliases `RecapSuperlativesQueryInterface` to its single
  DBAL implementation, and the provider is tagged through the interface's `#[AutoconfigureTag]` -
  verified live through the container in the functional tests.
- **Registration uniqueness in fixtures:** `registration` has a unique `(event_id, user_id)`
  constraint - the functional test reuses one confirmed registration per event+user across
  sessions (cache in the test class).
- **phpstan spy pattern:** an anonymous-class spy with a by-ref constructor array is flagged
  "property never read"; the spy now exposes a public `$calls` array read back by `runHandler`
  (typed via an `object{calls: ...}` intersection on the factory return).
- The recompute trigger in the handler fires only after a successful persist (early returns for
  missing/not-finished sessions never trigger), and `storeArchive`'s existing trigger is left in
  place for the stats facts.

### File List

**api/ (new)**
- `src/Community/Application/Query/RecapSuperlativesQueryInterface.php`
- `src/Community/Infrastructure/Dbal/DbalRecapSuperlativesQuery.php`
- `src/Community/Application/Support/RecapSuperlativesMetricProvider.php`
- `tests/Unit/Community/RecapSuperlativesMetricProviderTest.php`
- `tests/Functional/RecapSuperlativeAchievementTest.php`

**api/ (modified)**
- `src/Community/Domain/AchievementMetricCatalog.php` (FACT_SUPERLATIVES + SUPERLATIVE_PREFIX + 5 facts() entries)
- `src/Sessions/Application/Handler/BuildSessionRecapJobHandler.php` (post-persist achievement recompute for participants)
- `tests/Functional/BuildSessionRecapJobHandlerTest.php` (recompute spy + 2 new tests)

## Change Log

| Date       | Change |
|------------|--------|
| 2026-07-26 | Story implemented: recap superlatives feed the achievement engine (`superlatives` total + closed `superlative:{key}` facts, cross-context DBAL read of the projection, recompute triggered after the recap build to fix the storeArchive ordering gap). TDD throughout. |
