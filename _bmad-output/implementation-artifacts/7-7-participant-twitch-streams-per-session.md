# Story 7.7: Participant Twitch streams per session

Status: done

## Story

As a visitor of a session page (event, private/personal run, or weekly run),
I want to see which participants are streaming on Twitch and reach their channels,
so that I can watch the multiworld unfold from the players' points of view.

Extends the Epic 7 streaming surface (7-3 live detection, 7-4 live badge, 7-5 consent-gated embed),
which today only tracks the single official ArchiLAN channel. This story adds a per-session widget that
surfaces the **participants'** Twitch channels, with live channels first and offline channels below.

Deps: 7-3 (Twitch OAuth app-token + Helix client), 30-3/30-20 (social links on `CommunityProfile`).

## Acceptance Criteria

1. Each of the three session pages renders a "Streams des participants" widget listing participants of that
   session who have a Twitch link on their community profile:
   - Event detail: `app/(public)/evenements/[eventSlug]` (inline card)
   - Personal run detail: `app/(public)/runs/[runId]` (dedicated "Streams" tab)
   - Weekly: **public** game leaderboard `app/(public)/runs-hebdo/jeu/[gameSlug]` - an in-card "section"
     (styled like the category config block) inside each active category, shown **only** when the run is
     active (not finished) **and** at least one participant is live. (Not on the private `.../ma-run` page.)
2. Channels currently **live** are shown first with a live badge + viewer count; **offline** channels are
   listed below in a muted style. If no participant has a Twitch link, the widget renders nothing (no empty
   box, no error).
2b. The widget hosts **one shared Twitch player** (a single iframe). Clicking any participant card loads that
   participant's channel into that shared player in place; clicking another card swaps the channel. The iframe
   is **lazy**: it is not mounted until the first card is clicked (no third-party Twitch content loads on page
   view). The currently-playing card is visually marked as active. The shared player only renders on usable
   `sm+` viewports; below that, a card click opens `https://twitch.tv/{login}` in a new tab as fallback.
3. The Twitch login is extracted **server-side** from `community_profile.social_links` (shape
   `list<array{label: string, url: string}>`). A link counts as Twitch when its `label` resolves to the
   `twitch` type (case-insensitive, mirroring `resolveLinkType` in `social-links.ts`) **OR** its URL host is
   `twitch.tv` / `www.twitch.tv`. The login is the first non-empty path segment, lowercased, and must match
   the Twitch login grammar `^[a-z0-9_]{3,25}$`; anything else is ignored. At most one Twitch link per
   participant (the first valid one).
4. Live status is resolved with a **single batched** Twitch Helix call for all participant logins of the
   session (`GET /helix/streams?user_login=a&user_login=b...`, max 100 per call), reusing the existing OAuth
   app-token caching from 7-3. The result is cached ~60s. If Twitch is unconfigured or unavailable, every
   participant is treated as offline (graceful) and the links are still shown.
5. Three read endpoints return the participants sorted live-first:
   - `GET /api/v1/events/{eventId}/participant-streams`
   - `GET /api/v1/runs/{runId}/participant-streams`
   - `GET /api/v1/weekly-runs/{weeklyRunId}/participant-streams`
   Response shape: `{ "data": [ { "userId", "slug", "displayName", "twitchLogin", "avatarUrl", "live", "viewerCount" } ] }`
   (`avatarUrl` = Twitch profile image, batched via Helix `/users`, cached ~1h; null when unknown/unavailable).
   Excludes cancelled registrations (`status != 'reserved'`) and banned/suspended users
   (`user.banned_at IS NOT NULL` / `user.suspended_until` in the future). Unknown session id -> `404`.
6. Quality gates green: `phpstan` 0 / `php-cs-fixer` 0 / `app:architecture:ddd` exit 0 / `phpunit` 0
   notices-deprecations-warnings; `pnpm typecheck` / `lint` / `build` / `jest` clean.

## Tasks / Subtasks

- [x] **api/ Domain - `Streaming/Domain/TwitchLinkResolver.php`** (AC: 3)
  - [x] Pure, no I/O: `resolveLogin(array $socialLinks): ?string` taking `list<array{label,url}>`, returning
        the first valid Twitch login or `null`. Match by label (`twitch`, case-insensitive) or URL host
        `twitch.tv`/`www.twitch.tv`; parse first path segment; validate `^[a-z0-9_]{3,25}$`; strip query/fragment.
  - [x] Unit test `tests/Unit/Streaming/TwitchLinkResolverTest.php`: label match, url-host match, trailing
        slash, uppercase login normalised, query string stripped, non-twitch ignored, malformed url ignored,
        reserved paths (e.g. `videos`, `directory`) still parsed as login is acceptable (out of scope to filter).
- [x] **api/ Infrastructure - batch live check** (AC: 4)
  - [x] Add `fetchLiveLogins(array $logins): array` to `TwitchApiClientInterface`, returning
        `array<string, int>` mapping live login -> viewer count (absent key = offline). Empty input -> `[]`.
  - [x] Implement in `TwitchApiClient`: reuse the existing app-token logic, call Helix
        `/streams` with chunks of <=100 `user_login`, lowercase keys, tolerate failures by returning what
        succeeded (or `[]`). Do NOT touch the existing `fetchViewerCount()` used by 7-3.
  - [x] `NullTwitchApiClient::fetchLiveLogins()` returns `[]`.
- [x] **api/ Application - read model + facade** (AC: 4, 5)
  - [x] `Streaming/Application/ParticipantTwitchLinksQueryInterface.php` with three methods, each returning
        `list<array{userId:string, slug:string, displayName:?string, socialLinks:list<array{label:string,url:string}>}>`:
        `forEvent(string $eventId)`, `forPersonalRun(string $runId)`, `forWeeklyRun(string $weeklyRunId)`.
  - [x] `Streaming/Application/ParticipantStreamsView.php` facade: deps = the query interface +
        `TwitchApiClientInterface` + `CacheInterface`. Per session: load rows, resolve logins via
        `TwitchLinkResolver`, drop rows without a login, batch-check live, map to the response DTO, sort
        live-first then by displayName/slug. Returns `null` when the session does not exist (so the controller
        can 404) - distinguish "no session" from "session with zero streamers" (empty list).
  - [x] No `Connection`/DBAL in Application (repo rule). Cache the live-check result ~60s keyed by the sorted
        login set, not the raw query, so concurrent session pages share it.
- [x] **api/ Infrastructure - DBAL queries** (AC: 5)
  - [x] `Streaming/Infrastructure/DbalParticipantTwitchLinksQuery.php implements ParticipantTwitchLinksQueryInterface`.
        Mirror `DbalCommunityProfileQuery` conventions (`Connection::createQueryBuilder`, `quoteSingleIdentifier('user')`,
        `is_string` narrowing, decode `social_links` JSON). Joins:
        - event: `registration r` (where `r.status = 'reserved'`) -> `"user" u` -> `community_profile cp`, filtered by `r.event_id`.
        - personal run: `run_participant rp` -> `"user" u` -> `community_profile cp`, filtered by `rp.personal_run_id`.
        - weekly: `weekly_entries we` -> `"user" u` -> `community_profile cp`, filtered by `we.weekly_run_id` (distinct user).
        - all three: exclude `u.deleted_at IS NOT NULL`, `u.banned_at IS NOT NULL`, and active suspension.
  - [x] Existence of the parent session is checked in the same context's read side (reuse existing
        event/run/weekly lookups) so `ParticipantStreamsView` can return `null` on unknown id.
- [x] **api/ Presentation - `Streaming/Presentation/ParticipantStreamsController.php`** (AC: 5)
  - [x] Three routes (names `api_event_participant_streams`, `api_run_participant_streams`,
        `api_weekly_run_participant_streams`), each calling `ParticipantStreamsView`, wrapping
        `new JsonResponse(['data' => $list])`, `404` via the existing error-response pattern when the view
        returns `null`. Public (no auth) - matches the public session pages.
- [x] **api/ Tests** (AC: 3, 4, 5, 6)
  - [x] Functional `tests/Functional/ParticipantStreamsTest.php` extending `FunctionalTestCase`: seed users +
        community profiles with social links (Twitch / non-Twitch / none) + registrations|participants|entries,
        inject a fake `TwitchApiClientInterface` returning a chosen live set, assert ordering (live first),
        exclusion of cancelled/banned, `404` on unknown id, and empty `data` when nobody streams. One test per
        endpoint (or a data-provider).
- [x] **frontend - data layer `features/streaming/participant-streams-api.ts`** (AC: 1, 2)
  - [x] `fetchParticipantStreams(kind: "event"|"run"|"weekly", id: string): Promise<ParticipantStream[]>`
        using `apiFetch(`${env.apiBaseUrl}/...`)`, an `isParticipantStreamsPayload` type guard, `return []` on
        any failure. Types: `ParticipantStream = { userId; slug; displayName: string|null; twitchLogin; live; viewerCount: number|null }`.
- [x] **frontend - parametrized embed `features/streaming/participant-stream-embed.tsx`** (AC: 2b)
  - [x] `"use client"`, props `{ channel: string }`. Build the Twitch player iframe parametrized by `channel`
        (NOT the env channel): mirror the iframe construction in `consent-gated-twitch-embed.tsx` -
        `encodeURIComponent` the channel + parent hostname, `loading="lazy"`, accessible `title`, `sm+` only.
        This is the per-channel variant the existing single-channel embed cannot provide.
- [x] **frontend - widget `features/streaming/participant-streams.tsx`** (AC: 1, 2, 2b)
  - [x] `"use client"`, props `{ kind; id }`. TanStack Query: key `["participant-streams", kind, id]`,
        `staleTime: SESSION_STALE_TIME` (60s), `refetchInterval: 60_000`. Render nothing when list is empty.
        Live cards first (live badge + viewer count + "En direct"), offline below (muted, "Hors ligne").
  - [x] Hold the active channel in local state (`useState<string | null>(null)`). Clicking a card sets it and
        renders **one** `<ParticipantStreamEmbed channel={active} />` above/beside the card list; clicking
        another card swaps it; the active card is marked. The embed is mounted only once a channel is selected
        (lazy - no iframe on first paint). On `< sm` viewports, a card click instead opens
        `https://twitch.tv/{login}` in a new tab (`rel="noopener noreferrer"`). Do NOT mount one iframe per
        participant - a single shared player only.
  - [x] Embed the widget on the three pages (`evenements/[eventSlug]/page.tsx`, `runs/[runId]/...`,
        `runs-hebdo` member page), passing the right id (event uses the resolved event id from the server fetch).
  - [x] Jest `participant-streams-api.test.ts` (MSW): live-first ordering preserved from API, `[]` on non-200.
- [x] **Gates** - run all API + frontend gates listed in AC 6; fix to green.

### Review Findings

_Adversarial review (Blind Hunter / Edge Case Hunter / Acceptance Auditor), 2026-06-24._

- [x] [Review][Decision] **RESOLVED 2026-06-24 (product call: keep all three endpoints public, no gating).** Participant-streams endpoints are unauthenticated - private personal-run roster leaks - `ParticipantStreamsController` / `DbalParticipantTwitchLinksQuery::forPersonalRun`. `GET /api/v1/runs/{runId}` is auth+owner/participant gated (403), but `GET /api/v1/runs/{runId}/participant-streams` is public. **Accepted:** session participant streams are treated as public data (Twitch links the member chose to publish); no auth gate added to any of the three endpoints.
- [x] [Review][Decision] **RESOLVED 2026-06-24 (product call: ignore profile audience for session streams).** Social links exposed publicly ignore profile `audience` (default `members`) - `DbalParticipantTwitchLinksQuery`. `CommunityProfileView` gates `socialLinks` behind `community_profile.audience`; the new endpoints do not. **Accepted:** in the context of a session, participants' Twitch links are shown regardless of profile audience; no `audience` filter added.
- [x] [Review][Patch] Add a functional test for an *expired* suspension being included (only an active far-future one is covered); optionally harden `isBlocked` against an unparseable `suspended_until` [api/src/Streaming/Infrastructure/DbalParticipantTwitchLinksQuery.php]
- [x] [Review][Patch] Reconcile `activeChannel` with refetched data - clear/ignore it when the selected streamer drops out of the list [frontend/src/features/streaming/participant-streams.tsx]
- [x] [Review][Patch] Treat an empty/whitespace `display_name` as absent (fall back to slug) in `mapRows` [api/src/Streaming/Infrastructure/DbalParticipantTwitchLinksQuery.php]
- [x] [Review][Patch] Narrow `viewer_count` with `is_int` before storing it in the live map (external JSON, declared `array<string,int>`) [api/src/Streaming/Infrastructure/TwitchApiClient.php]
- [x] [Review][Defer] Twitch outage is cached as all-offline for the full 60s TTL - shortening the empty-result TTL trades outage-resilience for Helix quota (most sessions have nobody live) [api/src/Streaming/Application/ParticipantStreamsView.php] - deferred, design tradeoff
- [x] [Review][Defer] Label-"twitch" + non-Twitch host pulls a path segment as a login (cosmetic impersonation; ownership is unverifiable without per-user Twitch OAuth anyway) [api/src/Streaming/Domain/TwitchLinkResolver.php] - deferred, out of scope
- [x] [Review][Defer] Shared embed is `hidden sm:block`: resizing below sm after selecting keeps the iframe mounted/loading [frontend/src/features/streaming/participant-streams.tsx] - deferred, minor
- [x] [Review][Defer] Two distinct users sharing one Twitch login both render as active [frontend/src/features/streaming/participant-streams.tsx] - deferred, pathological
- Dismissed (noise): non-deterministic DBAL row order (the view re-sorts deterministically, dedup picks identical per-user profile data); "missing newline at EOF" of the resolver test (php-cs-fixer gate is green - artifact of the `--no-index` diff).

## Dev Notes

### Reuse, don't reinvent
- **OAuth app-token + Helix plumbing already exist** in `Streaming/Infrastructure/TwitchApiClient.php` (token
  cached at `streaming.twitch_app_token`, ~90% TTL). The new batch method reuses that token path - do not add
  a second auth flow.
- **Label->type resolution is canonical in the frontend** `features/community/social-links.ts`
  (`resolveLinkType`, key `"twitch"`). `TwitchLinkResolver` is the server-side mirror; keep the matching rule
  identical (case-insensitive label key) and additionally accept a `twitch.tv` URL host so links typed as
  "other" still work.
- **DBAL read crossing contexts is the established convention**: `DbalCommunityProfileQuery` already reads the
  Identity `"user"` table from the Community context. Reading `registration` / `run_participant` /
  `weekly_entries` + `community_profile` from a Streaming DBAL query is consistent - no new repositories in the
  owning contexts, no domain coupling.

### Architecture guardrails (gotchas that will bite)
- **The existing Twitch components are single-channel, NOT reusable per participant.** `TwitchStatusChecker.check()`
  and `fetchViewerCount()` only query the configured ArchiLAN `channelLogin`; `TwitchStatusProvider` /
  `useTwitchStatus` poll `/live/status` for that one channel; `ConsentGatedTwitchEmbed` / `TwitchPersistentPlayer`
  read `env.twitchChannelLogin` and take no channel prop. Build a fresh batched check (API), a fresh
  presentational widget, and a **channel-parametrized embed** (`ParticipantStreamEmbed`) - reuse the iframe
  construction and *styling* of `consent-gated-twitch-embed.tsx`, but driven by a `channel` prop, not these
  single-channel instances.
- **Batch, never N+1**: one Helix call per session for all logins (chunks of 100), not one call per participant.
  This is the whole reason `fetchLiveLogins` exists.
- **No DBAL in Application / no side effects in Domain** (root CLAUDE.md). `TwitchLinkResolver` is pure;
  `ParticipantStreamsView` orchestrates; DBAL stays in Infrastructure; the controller calls exactly one
  Application service and serialises `['data' => ...]`.
- **`new JsonResponse(['data' => ...])`** is the response convention (see `CommunityProfileController`). Error/404
  uses the existing api-access-guard error-response helper.
- **`PSR-3 LoggerInterface`** only for any logging (Twitch failure is swallowed to offline, so likely none) -
  never `error_log()`. **Frontend env via `src/lib/env.ts`**, never `process.env` directly.

### Scope boundaries / deviations
- **Twitch only.** YouTube and any other platform are explicitly out of scope (product decision). No YouTube
  Data API, no channel-id resolution.
- **No global `/en-direct` page** - per-session widget only.
- **One shared embed, loaded on click - not N embeds, not auto-load.** The single iframe mounts only after an
  explicit card click (user intent = the opt-in), so no third-party Twitch content loads on page view and the
  page never carries more than one participant player. The `< sm` fallback opens twitch.tv in a new tab. Do
  not build a cookie-consent hook here: the documented `archilan_twitch_consent` key is not wired to anything
  today, and lazy-on-click keeps the existing consent posture unchanged.
- **Social links are already public** (rendered on the profile header since 30-20), so no extra audience gating
  beyond excluding deleted/banned/suspended users.

### Project Structure Notes
- **Added (api):** `Streaming/Domain/TwitchLinkResolver.php`,
  `Streaming/Application/ParticipantTwitchLinksQueryInterface.php`,
  `Streaming/Application/ParticipantStreamsView.php`,
  `Streaming/Infrastructure/DbalParticipantTwitchLinksQuery.php`,
  `Streaming/Presentation/ParticipantStreamsController.php`,
  `tests/Unit/Streaming/TwitchLinkResolverTest.php`, `tests/Functional/ParticipantStreamsTest.php`.
- **Modified (api):** `Streaming/Infrastructure/TwitchApiClientInterface.php`,
  `Streaming/Infrastructure/TwitchApiClient.php`, `Streaming/Infrastructure/NullTwitchApiClient.php`.
- **Added (frontend):** `features/streaming/participant-streams-api.ts`,
  `features/streaming/participant-streams.tsx`, `features/streaming/participant-stream-embed.tsx`,
  `features/streaming/participant-streams-api.test.ts`,
  `app/(public)/streams/[kind]/[id]/page.tsx` (dedicated full-list overflow page).
- **Modified (frontend):** `app/(public)/evenements/[eventSlug]/page.tsx`,
  `features/personal-runs/personal-run-detail-page.tsx`,
  `features/weekly-runs/weekly-run-game-client.tsx` (public weekly game page - `variant="section"`, live-only).
- Service wiring: the Twitch client is already DI-registered for 7-3; register the new DBAL query + view +
  controller following the existing Streaming service config. Confirm `app:architecture:ddd` passes for the new
  cross-context Streaming DBAL query (same shape as `DbalCommunityProfileQuery`, which already passes).

### Reference signatures (verified in code)
- `TwitchApiClient` ctor: `(HttpClientInterface, CacheInterface, string $clientId, string $clientSecret, string $channelLogin)`;
  existing `fetchViewerCount(): ?int` hits `GET https://api.twitch.tv/helix/streams?user_login={channelLogin}`.
- `StreamStatus` (Domain, final readonly): `bool $live`, `?int $viewerCount`; `::live(int)`, `::offline()`.
- `StreamingController`: `#[Route('/api/v1/live/status', name: 'api_live_status', methods: ['GET'])]` ->
  `{ "data": { "live", "viewerCount" }, "meta": [] }`.
- Tables/columns: `registration(event_id, user_id, status, ...)`, `run_participant(personal_run_id, user_id, ...)`,
  `weekly_entries(weekly_run_id, user_id, ...)`, `community_profile(user_id UNIQUE, social_links JSON, display_name, ...)`,
  `"user"(id, slug, roles, deleted_at, banned_at, suspended_until)`.
- DBAL read template: `DbalCommunityProfileQuery` (`createQueryBuilder`, `quoteSingleIdentifier('user')`,
  `is_string` narrowing, returns plain arrays).
- Frontend: `apiFetch` (`src/lib/apiFetch.ts`), `env.apiBaseUrl` (`src/lib/env.ts`), TanStack constants
  `SESSION_STALE_TIME=60_000` / `DEFAULT_STALE_TIME=30_000` (`src/lib/query-client.ts`); type-guard +
  `try/catch -> []` pattern (`features/events/public-events-api.ts`); MSW jest setup (`src/tests/setup.ts`,
  `TEST_API_BASE_URL`).

### References

- [Source: _bmad-output/implementation-artifacts/7-3-twitch-live-status-detection.md] - Twitch live detection,
  OAuth token caching, offline fallback.
- [Source: _bmad-output/implementation-artifacts/7-5-consent-gated-twitch-embed.md] - existing embed posture
  (single channel, link fallback).
- [Source: _bmad-output/implementation-artifacts/30-20-typed-social-links.md] - typed social links,
  `resolveLinkType`, stored `{label,url}` shape unchanged.
- [Source: api/src/Streaming/] - `TwitchApiClient`, `TwitchStatusChecker`, `StreamStatus`, `StreamingController`.
- [Source: api/src/Community/Infrastructure/DbalCommunityProfileQuery.php] - DBAL cross-context read template.
- [Source: frontend/src/features/community/social-links.ts] - canonical link-type matching to mirror.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8

### Debug Log References

- Functional test initially failed `testBannedAndSuspendedParticipantsAreExcluded`: the suspension was set to
  the seed clock (May 2026) +7 days, already in the past versus the read layer's real-clock check. Fixed by
  suspending to a fixed far-future date (2099-01-01).
- Frontend lint flagged `react-hooks/refs` for reading `ref.current` during render in the embed; switched to a
  `useState` lazy initializer to resolve `window.location.hostname` (avoids both ref-in-render and the
  AC-HK2 setState-in-effect rule).

### Completion Notes List

- **Server-side login extraction** (`TwitchLinkResolver`, pure Domain): mirrors the frontend `resolveLinkType`
  label match and additionally accepts `twitch.tv` hosts; validates the Twitch login grammar. 13 unit tests.
- **Batch live check**: new `fetchLiveLogins()` on `TwitchApiClientInterface` / `TwitchApiClient` (one Helix
  `/streams` call per <=100 logins, reusing the 7-3 OAuth app-token), `NullTwitchApiClient` returns `[]`. The
  existing single-channel `fetchViewerCount()` is untouched.
- **Application facade** `ParticipantStreamsView`: resolves logins, drops non-streamers, batch-checks live
  (cached ~60s keyed by the sorted login set), sorts live-first then by name; returns `null` for an unknown
  session (so the controller 404s) vs `[]` for a session with no streamers.
- **DBAL read** `DbalParticipantTwitchLinksQuery`: joins each participant table -> `"user"` -> `community_profile`,
  excludes cancelled registrations and deleted/banned/suspended users, de-duplicates users (weekly attempts),
  and checks parent-session existence. No `DISTINCT` on the `json` column (Postgres has no equality operator
  for it) - dedupe is done in PHP.
- **Three public endpoints** under `ParticipantStreamsController`; wired `ParticipantTwitchLinksQueryInterface`
  -> the DBAL impl in `services.yaml`.
- **Frontend**: `participant-streams-api.ts` (typed fetch + guard, `[]` on failure), `participant-stream-embed.tsx`
  (channel-parametrized lazy iframe), `participant-streams.tsx` (client widget, live-first, one shared
  click-to-load player, `< sm` opens twitch.tv in a new tab, renders nothing when empty). Embedded on the event
  detail (server) page and inside the personal-run detail (overview tab) and weekly member-run client pages.
- **No new env vars.** Reuses `TWITCH_CLIENT_ID` / `TWITCH_CLIENT_SECRET` from 7-3 and `env.apiBaseUrl` on the
  frontend.
- **Personal-run UX (post-review tweak):** on the run detail page the widget moved out of the "Vue d'ensemble"
  tab into a dedicated **"Streams"** tab, and the existing OBS-overlay tab was relabelled **"Streaming" ->
  "Overlay Stream"** (tab key `streaming` -> `overlay`). The widget gained an `emptyState` prop (`"hide"` for
  the inline event/weekly placement, `"message"` for the dedicated run tab so it is never blank). Event and
  weekly placements are unchanged (still inline, render nothing when empty).
- **Gates**: phpstan 0, php-cs-fixer 0, `app:architecture:ddd` exit 0, phpunit 1398 tests / 10031 assertions
  green (0 notices); frontend typecheck / lint clean, jest 27 suites / 158 tests, `pnpm build` clean.

### File List

**Added (api)**
- `api/src/Streaming/Domain/TwitchLinkResolver.php`
- `api/src/Streaming/Application/ParticipantTwitchLinksQueryInterface.php`
- `api/src/Streaming/Application/ParticipantStreamsView.php`
- `api/src/Streaming/Infrastructure/DbalParticipantTwitchLinksQuery.php`
- `api/src/Streaming/Presentation/ParticipantStreamsController.php`
- `api/tests/Unit/Streaming/TwitchLinkResolverTest.php`
- `api/tests/Functional/ParticipantStreamsTest.php`

**Modified (api)**
- `api/src/Streaming/Infrastructure/TwitchApiClientInterface.php`
- `api/src/Streaming/Infrastructure/TwitchApiClient.php`
- `api/src/Streaming/Infrastructure/NullTwitchApiClient.php`
- `api/tests/Unit/Streaming/TwitchStatusCheckerTest.php` (fake client gains the new interface method)
- `api/config/services.yaml` (query interface binding)

**Added (frontend)**
- `frontend/src/features/streaming/participant-streams-api.ts`
- `frontend/src/features/streaming/participant-stream-embed.tsx`
- `frontend/src/features/streaming/participant-streams.tsx`
- `frontend/src/features/streaming/participant-streams-api.test.ts`

**Modified (frontend)**
- `frontend/src/app/(public)/evenements/[eventSlug]/page.tsx`
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx`
- `frontend/src/features/weekly-runs/weekly-run-slot-page.tsx`

## Change Log

| Date       | Change                                                            |
|------------|-------------------------------------------------------------------|
| 2026-06-24 | Implemented story 7.7 - participant Twitch streams per session.   |
| 2026-06-24 | Run-page UX: dedicated "Streams" tab + "Streaming" -> "Overlay Stream"; `emptyState` prop. |
| 2026-06-24 | Code review: 2 access decisions accepted as public (product call); 4 patches applied (expired-suspension test, embed reconcile, empty display-name fallback, viewer_count is_int). 4 deferred, 2 dismissed. |
| 2026-06-24 | Enhancement: Twitch profile pictures on the cards - new `fetchAvatars()` (Helix `/users`, batched ≤100, cached ~1h), `avatarUrl` added to the DTO/response, rendered with an icon fallback on the cards. |
| 2026-06-24 | Weekly placement moved: removed from the private `.../ma-run` page; added a public live-only `variant="section"` inside each active category on `runs-hebdo/jeu/[gameSlug]` (visible only when the run is active and someone is live). |
| 2026-06-24 | Relevance guard: `forWeeklyRun` returns `[]` when the weekly run is not `active`; `forEvent` returns `[]` when the event is `completed` (a finished session never surfaces an unrelated live stream). Frontend also hides the widget on a completed event. Functional tests added for both. |
| 2026-06-24 | Cap: the widget renders at most 10 cards (live first); beyond that it links to a new dedicated full-list page `/streams/[kind]/[id]` (`showAll` prop bypasses the cap there). |