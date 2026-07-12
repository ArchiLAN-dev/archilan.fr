# Story 32.1: Récap public et narratif d'une partie

Status: ready-for-review

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a visitor or player,
I want a public page that tells the story of a finished session (item-exchange graph, goal
timeline, podium, named superlatives),
so that I can relive and share the run, and draw the French-speaking community toward Archipelago.

First story of Epic 32 - *Récap de partie*. This is a **community / growth** surface: the
item-exchange graph is unique to Archipelago, highly shareable (SEO francophone = ArchiLAN's
mission) and gives a reason to come back after an event. It builds on data already archived by
Story 9.16 and **reuses** existing read paths rather than duplicating them.

Depends on: 9.16 (run archival + spoiler archived on `Session.archivedSpoilerPath`, read via
`SessionSpoilerArtifactReaderInterface` → `SpoilerArtifact{filename, contents}`), 3.8 (VOD attached
to an event), 30.4 (achievements catalog, optional surfacing).

## Context

The public results page (`/resultats`) and the per-slot ranking already exist
(`RunResultsQuery` / `RunResultsController` at `GET /api/v1/runs/{id}/results`). This story does
**not** redo them - it reuses `RunResultsQuery` for the podium and adds the **narrative** layer on
top: the who-sent-what-to-whom graph, the goal timeline, and named superlatives.

The only data not captured today is **item provenance** (which slot's location holds which slot's
item). That information lives in the `.archipelago` **spoiler log**, which Story 9.16 already
archives. So the new work is: parse the spoiler once into a persisted projection, compute
superlatives, and expose a public page.

### Architecture decisions

1. **Source of truth = the generated-output spoiler.** Read via
   `SessionSpoilerArtifactReaderInterface::extractSpoiler($session->getGeneratedOutputKey())`
   (Infra IO already exists - `MinioZipSpoilerArtifactReader` downloads `{sessionId}/output/archive.zip`
   from MinIO and returns the `*_spoiler*` entry, **excluding** the `.archipelago` multidata). The
   `Locations:` section of that spoiler is the edge list `slotSource → slotDest (itemName, count)`.
   Note: Story 9.16's prose calls the `.archipelago` file the "spoiler log" - that is the binary
   **multidata**, not the human-readable spoiler. The reader is already correct; the recap uses
   `generatedOutputKey`, **not** `archivedSpoilerPath` (a runner-local filesystem path).
2. **Persisted projection, never parse-on-read.** Parse **once at archival time** into a
   `SessionRecap` read-model (nodes + aggregated edges + superlatives + duration). The public page
   reads the projection → fast, and resilient to later loss of the spoiler file.
3. **Pure parser in Application** (`SpoilerGraphParser`, no IO - receives the string) per AC-A. The
   file read (IO) stays in Infrastructure.
4. **Reuse:** podium/ranking = `RunResultsQuery`; VOD = event VOD field (3.8); achievements = 30.4.
5. **Privacy:** recap is public **only** for a `finished` session attached to a **public event**.
   Personal/private runs are never exposed (the endpoint 404s).

## Acceptance Criteria

1. **Read-model.** New `SessionRecap` projection - repository interface in `Sessions/Domain`
   (`SessionRecapRepositoryInterface`), DBAL implementation in `Sessions/Infrastructure`. Shape:
   `sessionId`, `generatedAt`, `nodes[] {slotId, playerName, game, goalReachedAt}`,
   `edges[] {fromSlotId, toSlotId, itemName, count}`, `superlatives[] {key, label, slotId, value}`.
   Migration deferred to Jean (per project convention - no migration written in-story).
2. **Parser.** `SpoilerGraphParser` (Application, **pure**, unit-tested) turns the spoiler contents
   into nodes + aggregated edges (one edge per `from→to` pair, `count` summed). It tolerates a
   missing/unreadable/unexpected spoiler: returns a stats-only recap (nodes, no edges), never throws
   an unhandled exception.
3. **Build at archival.** After Story 9.16's `archived` callback succeeds, a
   `BuildSessionRecapJob{sessionId}` is dispatched (Messenger). Its handler reads the spoiler via
   `SessionSpoilerArtifactReaderInterface`, parses it, computes superlatives, and persists the
   `SessionRecap`. Idempotent - a rebuild replaces the prior projection.
4. **Superlatives.** At least: most generous (max items sent to others), first to goal, longest
   road to goal, biggest hub (slot unlocking the most distinct other slots). Labels named in the
   **pop-culture / cinema style** of ArchiLAN achievements (not generic).
5. **Public endpoint.** `GET /api/v1/parties/{sessionId}/recap` - no auth; 404 when the session is
   not `finished` or its event is not public; returns the projection + podium (via `RunResultsQuery`)
   + duration + VOD URL when present. Controller obeys AC-P3/AC-P4 (one Application call → a facade
   `SessionRecapQuery` composes projection + podium + VOD).
6. **Public page.** `frontend/src/app/(public)/parties/[sessionId]/page.tsx`: header (event,
   duration, podium), **interactive force-directed exchange graph** (node = player+game, edge = item
   flow, thickness ∝ count), goal timeline, superlatives panel, consent-gated VOD embed (reuse the
   7.5 pattern), Open Graph metadata for sharing. Empty/edgeless recap → friendly placeholder, no
   broken layout.
7. **Discovery.** A "Voir le récap" link from the existing `/resultats` page and from the event
   recap surface.
8. **Tests.** Unit: `SpoilerGraphParser` (multi-slot placement, empty spoiler, unexpected format)
   and `RecapSuperlativesCalculator`. Functional: job dispatched on archival; public endpoint no-auth
   200 + shape; 404 on private/personal run and on non-finished session.
9. **Gates green:** backend (php-cs-fixer, phpstan max, phpunit 0 notices, `app:architecture:ddd`)
   and frontend (typecheck, lint, build, jest).

## Tasks / Subtasks

- [x] **T1 - Read-model (AC #1).** `SessionRecap` ORM entity (slot-id-keyed JSON projection) +
  `SessionRecapRepositoryInterface` (Domain) + `DoctrineSessionRecapRepository` (Infra), wired in
  `services.yaml`. Migration for the `session_recap` table flagged for Jean (functional tests build
  the schema via SchemaTool). *(Chose an ORM entity over raw DBAL so the schema is created in tests
  and Doctrine mapping is automatic - Sessions stays flat/unmigrated.)*
- [x] **T2 - Parser (AC #2).** `SpoilerGraphParser` (pure) + `RecapGraph/RecapNode/RecapEdge` DTOs.
  Unit-tested against the committed fixture (exact edge/local aggregates) + synthetic
  paren/self-edge/empty/malformed cases. Anchored from the right for parenthesised names.
- [x] **T3 - Superlatives (AC #4).** `RecapSuperlativesCalculator` (pure): most generous, biggest
  hub, first to goal, longest road; ArchiLAN-style labels; deterministic tie-breaking; unit-tested.
- [x] **T4 - Build job (AC #3).** `BuildSessionRecapJob` + handler; dispatched post-commit from
  `SessionLifecycleManager::storeArchive` (routed to the `async` transport). Reconciles slot names ->
  slot ids/goal times; idempotent rebuild; stats-only fallback on missing/unreadable spoiler.
- [x] **T5 - Query + endpoint (AC #5).** `SessionRecapQuery` facade (projection + reused
  `RunResultsQuery` podium + event VOD) and `SessionRecapController` at
  `GET /api/v1/parties/{sessionId}/recap` (public; 404 on not-finished / non-public-event /
  personal-or-weekly run / no projection).
- [x] **T6 - Frontend (AC #6).** `/parties/[sessionId]` page + `features/recap/` module: cached
  fetcher with full-shape guards, hand-rolled client-only force-directed `<canvas>` exchange graph
  (no graph dependency), superlatives, podium, goal timeline, consent-gated Twitch VOD embed, OG
  metadata, friendly not-found.
- [x] **T7 - Discovery links (AC #7).** "Voir le récap" link added to the run results header.
- [x] **T8 - Functional tests + gates (AC #8, #9).** Handler reconciliation + stats-only + idempotent
  rebuild; endpoint 200 shape + four 404 cases. Full suite green (1510 tests); `composer gates` +
  `pnpm gates` green.

## Dev Notes

### Spoiler format - **confirmed against a real fixture**
- Fixture committed: `api/tests/Fixtures/Sessions/sample_AP_Spoiler.txt` (real 3-player run,
  Archipelago 0.6.7: Luigi's Mansion / Super Mario 64 / The Wind Waker). Use it for T2/T3 unit tests.
- **Player → game mapping** comes from the header blocks:
  ```
  Player 1: Player1
  Game:                            Luigi's Mansion
  ```
  i.e. `Player <n>: <name>` followed (within the block) by `Game:<ws><gameName>`.
- **Edge list** comes from the `Locations:` section. Each line is:
  ```
  <LocationName> (<HostPlayer>): <ItemName> (<ItemOwnerPlayer>)
  ```
  Semantics: when `HostPlayer` reaches that location, `ItemName` is sent **to** `ItemOwnerPlayer`.
  So each line is an edge `HostPlayer → ItemOwnerPlayer` labelled `ItemName`. Lines where
  `HostPlayer == ItemOwnerPlayer` are **local items** (self-edges) - aggregate them separately
  (count toward "items kept local"), don't draw them as exchange arcs.
- Parsing caveats baked into the fixture: location/item **names themselves contain parentheses**
  (e.g. `Armory Gray Chest (left, back Wall) (Player1): ...`). The owner is the **last**
  parenthesised group on the line - parse from the right (`(<owner>)` at end, then `:` splits
  location-side from item-side). Do not split naively on the first `(`.
- The section ends at the next blank-line-delimited header (`Starting Items:`, `Playthrough:`,
  `Paths:`). `Starting Items:` lines (`<Item> (<Player>)`) feed the start-inventory but are not
  exchange edges.
- Aggregate to one edge per `(from,to)` pair after a `(from,to,item)` pass (sum counts; optionally
  keep the per-item breakdown for tooltips).

### Graph density
- A 50-slot run can produce a dense graph. **Aggregate edges per `from→to` pair** (sum `count`),
  not one edge per item. Consider a front-side display threshold/top-N edges with a "show all"
  toggle.

### Reuse, do not duplicate
- Podium/ranking: call existing `RunResultsQuery` (already sorts GOAL slots by completion, handles
  invalidated/released slots). Do not re-implement ranking.
- VOD: reuse the event VOD field from Story 3.8 and the consent-gated embed pattern from Story 7.5.

### DDD compliance
- Parser + superlatives = pure Application classes (string in, DTO out), no IO, no clock - inject
  any needed time as a parameter (AC-D3/AC-A).
- Spoiler file read stays in Infrastructure behind `SessionSpoilerArtifactReaderInterface`.
- Public controller: `JsonResponse` only, single Application call (AC-P3/P4/P5).
- `ROLE_MEMBER` must not gate anything here (page is fully public for public events).

### Epic 32 - remaining stories (proposed, not yet written)
- 32.2 - share card / OG image generation for `/parties/{id}`.
- 32.3 - recap index per event ("toutes les parties de cet event").
- 32.4 - tie achievements (30.4) to recap superlatives (e.g. "le plus généreux" unlock).

## Dev Agent Record

### Decisions taken during implementation

- **Read-model is an ORM entity, not a raw DBAL table.** The story said "DBAL implementation"; an
  ORM entity (`Sessions/Domain/SessionRecap`, JSON columns) was chosen instead so `SchemaTool` builds
  the table for functional tests and Doctrine mapping is automatic (`Sessions/Domain` is already a
  mapped dir). The repository interface/impl split the story asked for is preserved.
- **The projection is entirely slot-id-keyed.** The parser can only see slot *names* (all the spoiler
  exposes). The build handler reconciles name -> `slotId` (+ goal time) against the session slots, so
  the stored graph joins cleanly to the podium by `slotId`. Display names are *not* snapshotted -
  they are read live from `RunResultsQuery`, so a later rename stays consistent.
- **Stats-only fallback keeps its time-based superlatives.** With no/unreadable spoiler the exchange
  graph is empty (no nodes/edges, no `most_generous`/`biggest_hub`), but `first_to_goal` /
  `longest_road` still stand: they come from the slots' goal times, not the spoiler.
- **`BuildSessionRecapJob` routed to `async`** (not `run_server`): it runs on the central API side
  (MinIO + DB). This also keeps it off the synchronous path in tests.
- **No graph dependency added.** No force-directed lib is installed and none was added; the graph is a
  hand-rolled client-only `<canvas>` simulation following the project's existing canvas lifecycle
  (`grav-wave.tsx`): deterministic seed (no `Math.random`, AC-HK3), DPR-aware resize, RAF +
  ResizeObserver with full cleanup, hover/drag, reduced-motion settle, and a visually-hidden table
  mirror for a11y/crawlers.

### Deferred / follow-up

- **Migration for the `session_recap` table is deferred to Jean** (project convention - no migration
  written in-story). Until it lands, the endpoint 404s in a real environment because the table is
  absent; functional tests are unaffected (SchemaTool).
- **Pre-existing finished sessions have no projection** and therefore 404 on `/parties/{id}` - a
  backfill command was out of scope.
- The "Voir le récap" link on the run results header is unconditional; for a personal/weekly run it
  lands on the friendly not-found page. Gating it needs a public-event flag on the results payload -
  a small follow-up.
- **AC #7 is only half done.** The link from `/resultats` is in. The link from the *event recap
  surface* is **not**: an event has N sessions, so that surface needs to pick *which* party to link -
  which is exactly the per-event recap index of proposed story **32.3**. Deferred there rather than
  guessing a session here.

### File List

**api/ (new)**
- `src/Sessions/Application/RecapNode.php`, `RecapEdge.php`, `RecapGraph.php`, `RecapSuperlative.php`
- `src/Sessions/Application/SpoilerGraphParser.php`
- `src/Sessions/Application/RecapSuperlativesCalculator.php`
- `src/Sessions/Application/SessionRecapQuery.php`
- `src/Sessions/Application/Message/BuildSessionRecapJob.php`
- `src/Sessions/Application/Handler/BuildSessionRecapJobHandler.php`
- `src/Sessions/Domain/SessionRecap.php`, `SessionRecapRepositoryInterface.php`
- `src/Sessions/Infrastructure/DoctrineSessionRecapRepository.php`
- `src/Sessions/Presentation/SessionRecapController.php`
- `tests/Unit/Sessions/SpoilerGraphParserTest.php`, `RecapSuperlativesCalculatorTest.php`
- `tests/Functional/BuildSessionRecapJobHandlerTest.php`, `SessionRecapEndpointTest.php`
- `tests/Fixtures/Sessions/sample_AP_Spoiler.txt` (fixture, landed with the planning commit)

**api/ (modified)**
- `src/Sessions/Application/SessionLifecycleManager.php` (post-commit dispatch in `storeArchive`)
- `config/services.yaml` (recap repository binding)
- `config/packages/messenger.yaml` (route `BuildSessionRecapJob` to `async`)

**frontend/ (new)**
- `src/app/(public)/parties/[sessionId]/page.tsx`
- `src/features/recap/recap-api.ts`, `session-recap-page.tsx`, `exchange-graph.tsx`, `recap-vod.tsx`

**frontend/ (modified)**
- `src/features/runs/run-results-page.tsx` ("Voir le récap" discovery link)

### Gates

- `phpstan analyse src tests` - 0 errors; `php-cs-fixer check` - 0; `app:architecture:ddd` - OK.
- `php bin/phpunit` (isolated DB) - **1510 tests, 10420 assertions, green**.
- `pnpm gates` (typecheck / lint / jest / build) - green.
