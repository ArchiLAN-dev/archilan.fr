# Story 32.1: Récap public et narratif d'une partie

Status: draft

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

- [ ] **T1 - Read-model (AC #1).** `SessionRecap` value/DTO + `SessionRecapRepositoryInterface`
  (Domain) + DBAL impl (Infra). Flag migration for Jean.
- [ ] **T2 - Parser (AC #2).** `SpoilerGraphParser` in `Sessions/Application` (pure). Unit tests
  built from the committed fixture `api/tests/Fixtures/Sessions/sample_AP_Spoiler.txt` (see Dev
  Notes for the exact line format). Cover empty and malformed inputs.
- [ ] **T3 - Superlatives (AC #4).** `RecapSuperlativesCalculator` (pure) over edges +
  `goalReachedAt`. Named labels.
- [ ] **T4 - Build job (AC #3).** `BuildSessionRecapJob` + handler; dispatched after the `archived`
  callback in `SessionLifecycleManager` / `RunnerCallbackController`. Idempotent.
- [ ] **T5 - Query + endpoint (AC #5).** `SessionRecapQuery` facade (projection + `RunResultsQuery`
  + VOD) and `SessionRecapController`.
- [ ] **T6 - Frontend (AC #6).** `/parties/[id]` page, force-directed graph component (lightweight
  Next 15-compatible lib, client-only canvas - to be chosen here), timeline, superlatives,
  consent-gated VOD embed, OG metadata.
- [ ] **T7 - Discovery links (AC #7).** From `/resultats` and the event recap.
- [ ] **T8 - Functional tests + gates (AC #8, #9).**

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
