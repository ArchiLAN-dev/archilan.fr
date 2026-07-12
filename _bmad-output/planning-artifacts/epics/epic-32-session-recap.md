# Epic 32 - Récap de partie (public session recap)

Status: planned (not started)
Date: 2026-06-24

## Goal

Turn a finished multiworld into **shareable, public, narrative content**: a page that tells the
story of the run - the **item-exchange graph** (who sent what to whom), the **goal timeline**, the
**podium**, and named **superlatives**. The exchange graph is unique to Archipelago, visually
distinctive, and highly shareable - exactly the kind of artefact that draws the French-speaking
community toward Archipelago (ArchiLAN's core mission) and gives players a reason to come back after
an event.

This is a **community / growth** epic, not a stats epic. It builds on data **already archived** by
Story 9.16 and deliberately **reuses** the existing public results read path rather than rebuilding
it.

## Decisions (locked)

- **Source of truth = the generated-output spoiler, parsed once at archival.** The
  `MinioZipSpoilerArtifactReader` (Infra, already exists) downloads `{sessionId}/output/archive.zip`
  from MinIO via `Session.generatedOutputKey` and returns the `*_spoiler*` entry (it already
  **excludes** the `.archipelago` multidata). The `Locations:` section of that spoiler is the edge
  list. **Note:** Story 9.16's prose calls the `.archipelago` file the "spoiler log" - that is the
  binary multidata, not the human-readable spoiler. The reader is already correct; the recap uses
  `generatedOutputKey`, never `archivedSpoilerPath` (a runner-local FS path).
- **Persisted projection, never parse-on-read.** Parse the spoiler **once** (at archival) into a
  `SessionRecap` read-model (nodes + aggregated edges + superlatives + duration). The public page
  reads the projection - fast, and resilient to later loss of the spoiler file.
- **Pure parser in Application.** `SpoilerGraphParser` receives the spoiler **string** and returns a
  DTO - no IO, no clock (AC-D3/AC-A). The file read (IO) stays behind
  `SessionSpoilerArtifactReaderInterface` in Infrastructure.
- **Reuse, don't rebuild.** Podium/ranking = the existing `RunResultsQuery` (already sorts GOAL
  slots by completion, handles released/invalidated slots). VOD = the event VOD field (Story 3.8).
  Consent-gated embed = the Story 7.5 pattern. Achievements = Epic 30.4. Public-page chrome,
  duration formatting, and SEO/OG follow Epic 10 / Story 18.5 conventions.
- **Privacy = finished + public event only.** The public recap exists only for a `finished` session
  attached to a **public event**. Personal/private runs are never exposed - the endpoint 404s.
  `ROLE_MEMBER` gates nothing here (the page is fully public for public events).
- **Backend home = `Sessions` context.** The data, the readers, the results query, and the archival
  flow already live in `Sessions`. The recap projection + parser + build job + public read all live
  there. No new bounded context.

## Spoiler format (confirmed against a real fixture)

A real 3-player spoiler (Archipelago 0.6.7: Luigi's Mansion / Super Mario 64 / The Wind Waker) is
committed at `api/tests/Fixtures/Sessions/sample_AP_Spoiler.txt`. The parser is built against it.

- **Player → game** from the header blocks: `Player <n>: <name>` then, within the block,
  `Game:<ws><gameName>`.
- **Edges** from the `Locations:` section, one line each:
  `<LocationName> (<HostPlayer>): <ItemName> (<ItemOwnerPlayer>)`. Semantics: when `HostPlayer`
  reaches the location, `ItemName` is sent **to** `ItemOwnerPlayer` - i.e. an edge
  `HostPlayer → ItemOwnerPlayer` labelled `ItemName`. Lines where host == owner are **local items**
  (self-edges) - aggregated separately, not drawn as exchange arcs.
- **Parsing caveat:** location/item names themselves contain parentheses
  (`Armory Gray Chest (left, back Wall) (Player1): ...`). The owner is the **last** parenthesised
  group; parse from the right, then split on `:` between location-side and item-side. Never split on
  the first `(`.
- Section ends at the next header (`Starting Items:`, `Playthrough:`, `Paths:`). `Starting Items:`
  lines feed the start inventory, not the exchange graph.

## Scope

### In scope
- `SessionRecap` read-model (repo interface in `Sessions/Domain`, DBAL impl in
  `Sessions/Infrastructure`) + migration (Jean).
- `SpoilerGraphParser` (pure, Application) + `RecapSuperlativesCalculator` (pure, Application).
- `BuildSessionRecapJob` + handler, dispatched after Story 9.16's `archived` callback; idempotent.
- Public read: `SessionRecapQuery` facade (projection + `RunResultsQuery` podium + VOD) and
  `SessionRecapController` at `GET /api/v1/parties/{sessionId}/recap` (no auth; 404 on
  not-finished / non-public-event / personal run).
- Public page `frontend/src/app/(public)/parties/[sessionId]/page.tsx`: header + podium, interactive
  force-directed exchange graph, goal timeline, superlatives panel, consent-gated VOD embed, OG
  metadata. "Voir le récap" links from `/resultats` and the event recap surface.

### Out of scope (open doors, not built here)
- Server-rendered share-card / OG **image** generation (candidate 32.2).
- A per-event recap **index** ("toutes les parties de cet event") (candidate 32.3).
- Tying achievements (30.4) to recap superlatives (candidate 32.4).
- Hint history, per-location spoiler browsing, or any item-by-item drill-down beyond aggregated
  edges + tooltips.
- Real-time recap during a running session (the recap is a post-`finished` artefact).
- Personal/private-run recaps (privacy decision above).

## Affected systems (anticipated)

- **api/ `Sessions`** - new `SessionRecap` domain read-model + `SessionRecapRepositoryInterface`
  (Domain) + DBAL impl (Infra) + migration; `SpoilerGraphParser` + `RecapSuperlativesCalculator`
  (Application, pure); `BuildSessionRecapJob` (`Application/Message`) + handler
  (`Application/Handler`); `SessionRecapQuery` facade + `SessionRecapController` (Presentation).
  Reuses `SessionSpoilerArtifactReaderInterface`, `RunResultsQuery`, `Session.generatedOutputKey`,
  and the event VOD field.
- **api/ archival hook** - `SessionLifecycleManager` / `RunnerCallbackController` dispatch
  `BuildSessionRecapJob` once the `archived` callback (9.16) has stored the output key + stats.
- **frontend/** - new `app/(public)/parties/[sessionId]/page.tsx`, a `features/recap/` module
  (recap-api with type guard, the exchange-graph component, timeline, superlatives panel), a
  client-only force-directed graph (lightweight, Next 15 / RSC-compatible - chosen in 32.1), reuse
  of the consent-gated Twitch/VOD embed (7.5), and "Voir le récap" links from the results page +
  event recap.
- **Config** - no new env. Reuses the existing MinIO sessions bucket + Mercure/embed config.

## Proposed stories

- **32.1 - Public session recap: projection, parser, build job, public page (api/ + frontend).**
  The whole vertical slice. `SessionRecap` read-model + repo + migration; pure `SpoilerGraphParser`
  (built against the committed fixture) + `RecapSuperlativesCalculator` (most generous, first to
  goal, longest road, biggest hub - labels named in the ArchiLAN pop-culture style);
  `BuildSessionRecapJob` dispatched after the 9.16 `archived` callback (idempotent, tolerant of a
  missing/unreadable spoiler → stats-only recap); `SessionRecapQuery` + public
  `GET /api/v1/parties/{sessionId}/recap` (404 on not-finished / non-public / personal run);
  `/parties/[id]` page with the force-directed exchange graph, timeline, podium, superlatives,
  consent-gated VOD; discovery links. Unit tests (parser, superlatives) + functional tests (job on
  archival, public endpoint shape + auth, 404 cases) + all 7 gates.
  *(Story file already drafted: `implementation-artifacts/32-1-public-session-recap.md`.)*
- **32.2 (later) - Share card / OG image for `/parties/{id}` (api/ + frontend).** Generate a
  server-side share image (podium + headline superlative) so links unfurl richly on Discord/Twitter.
  Pure follow-on; depends on 32.1's projection.
- **32.3 (later) - Per-event recap index (frontend + small api/).** "Toutes les parties de cet
  event" listing finished sessions with their recap links; surfaced on the event detail page.
- **32.4 (later) - Achievements from recap superlatives (api/).** Wire 32.1's superlatives into the
  Epic 30.4 achievement engine (e.g. "le plus généreux" unlock), via an
  `AchievementMetricProvider` over the recap projection - no rule-engine change.

## Sequencing

`32.1` is the self-contained MVP and ships the whole vertical slice. **Within 32.1**, build the pure
`SpoilerGraphParser` + its unit tests against the committed fixture **first** (it is the isolated,
highest-risk brick), then the projection + build job, then the public read + page. `32.2`/`32.3`/
`32.4` are independent follow-ons that all depend only on 32.1's persisted projection and can be
scheduled in any order.

## Risks / notes

- **Spoiler format drift.** The `_Spoiler.txt` layout varies by Archipelago version and by apworld.
  Mitigation: parse defensively (parse owner from the right; tolerate unknown/extra lines), keep the
  committed fixture as the contract, and on any unparseable spoiler fall back to a **stats-only
  recap** (nodes + podium, no edges) rather than failing the build.
- **Graph density at scale.** A 50-slot run (venue cap) yields a dense graph. Aggregate edges per
  `(from,to)` pair (sum counts; keep per-item breakdown only for tooltips) and apply a front-side
  top-N / "show all" threshold so the canvas stays readable.
- **Front graph lib.** Choose a lightweight, client-only force-directed renderer compatible with
  Next 15 (no SSR of the canvas). Decided in 32.1's T6; avoid heavy/deprecated deps (cf. Epic 30.23
  lesson - the literal-flames / Lottie experiments were removed).
- **Build trigger ordering.** `BuildSessionRecapJob` must run **after** the 9.16 `archived` callback
  has persisted `generatedOutputKey` + slot stats, otherwise the spoiler key is null. Dispatch from
  the callback path, not from the `finished` transition.
- **Privacy leak surface.** The recap exposes per-player item flow publicly. Gate strictly on
  `finished` + public event; never expose personal/private runs; do not surface hints or
  un-found-location spoilers beyond the placement graph the page is designed to show.
- **Foundation already in place.** The spoiler reader, the output archive, `RunResultsQuery`, the
  VOD field, the consent-gated embed, and the achievement metric-provider mechanism all exist - this
  epic is mostly the parser + a persisted projection + one public page.

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-24 | Epic planned from a community value-add discussion. Public narrative recap of a finished multiworld: item-exchange graph + timeline + podium + named superlatives, anchored on `sessionId`, in the `Sessions` context. Source = the generated-output spoiler parsed once at archival into a persisted `SessionRecap` projection (reuses `SessionSpoilerArtifactReaderInterface` + `RunResultsQuery`; corrects 9.16's `.archipelago`/spoiler terminology). Spoiler format confirmed against a committed real fixture (`api/tests/Fixtures/Sessions/sample_AP_Spoiler.txt`). Story 32.1 (full vertical slice) drafted; 32.2 (OG image), 32.3 (per-event index), 32.4 (achievements from superlatives) proposed as follow-ons. |
