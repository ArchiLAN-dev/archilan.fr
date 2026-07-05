# Story 33.8: Tech-Debt Cleanup & Deferred-Item Triage (api/ + frontend/)

Status: ready-for-review

## Story

As a maintainer of the monorepo,
I want every item in `deferred-work.md` re-triaged (fixed or formally accepted) and the dead-code/TODO residue of the 33.5-33.7 audits closed out,
so that the deferred ledger reflects reality and nothing surfaced by the epic's sweeps is silently dropped.

## Acceptance Criteria

1. **AC1 - deferred-work.md re-triaged.** Each of the 4 story-7.7 items is either fixed or formally accepted with a recorded rationale; the file is updated to reflect the outcome (this story's triage table below is the audit of record, committed first).
2. **AC2 - Audit residue closed.** Dead code and stale TODOs surfaced by 33.5/33.6/33.7 are verified removed (they were: 33.6 deleted the Discord shims + reference.php and resolved the phpstan TODO; 33.7 deleted legal-placeholder; zero TODO/FIXME remains in frontend/src; the only api TODO is the intentional `TODO epic-32` allowlist comment). Non-trivial residues are formally bounced to their own recorded story candidates, not absorbed: ClockInterface migration (~130 sites, 33.5-D), AC-D5 setter refactor (25 setters, 33.5/33.6-D), TanStack Query migration (~14 pages + apiFetch relocation, 33.7-D), typed SSE layer (~22 casts, 33.7-D), Sessions deferrals (TODO epic-32: Session/SessionSlot final, NullRunnerGateway statics, RunnerCallbackClient port).
3. **AC3 - All gates green.** `composer gates` + `pnpm gates` pass; behaviour changes are limited to the two approved fixes below (faster outage self-heal; embed unmount below `sm`).

## Triage table (AC1 - the audit of record)

| # | Deferred item (7.7 review) | Verdict | Detail |
|---|---------------------------|---------|--------|
| D1 | Twitch outage cached as all-offline for 60s (`ParticipantStreamsView::liveMap`) | **FIX** | Root cause: `fetchLiveLogins()` collapses "Twitch unreachable" and "nobody live" into `[]`. Change the port contract to `array|null` (null = unavailable: token fetch failed, or every chunk failed; `[]` stays authoritative-empty; missing credentials stays `[]` - permanent config, not an outage). `liveMap` caches null as `[]` with a 15s TTL (self-heal ≤15s) vs 60s for authoritative data. Helix quota unaffected in the common case. Update the 2 test fakes; add a `ParticipantStreamsViewTest` covering both TTL paths. |
| D2 | Label-"twitch" + non-Twitch host yields attacker-chosen login | **ACCEPT** | Unfixable without per-user Twitch OAuth, and pointless: a user can put a real `twitch.tv/<anyone>` URL anyway; the login is grammar-validated; ownership is unverifiable by design. Displaying someone else's channel on your own card is self-defeating vandalism with no privilege gain. |
| D3 | Shared embed hidden but loaded below `sm` (`participant-streams.tsx`) | **FIX** | Unmount instead of hide: `useSyncExternalStore`-based `(min-width: 640px)` subscription gating the embed render (SSR snapshot true; the existing `hidden sm:block` class stays as paint guard). The component already consults the same media query in its click handler - this aligns render with that logic. |
| D4 | Same Twitch login across two distinct users: both cards highlight, shared embed ambiguous | **ACCEPT** | Pathological input (requires two users claiming one channel); backend dedups by userId so data is correct; the embed shows the (single) shared channel, which is the only sensible rendering. No defect to fix. |

## Tasks / Subtasks

- [x] Task 1: Triage of record committed before code changes → `bafc5fc`.
- [x] Task 2: D1 fix - `fetchLiveLogins(): ?array` (null = token failure or every chunk failed; missing credentials stays `[]`); `liveMap` caches an outage 15s vs 60s authoritative; `NullTwitchApiClient` unchanged (covariant); both test fakes unchanged (covariant narrower returns remain valid); new `ParticipantStreamsViewTest` (3 tests: outage → offline + 15s, live data → 60s, authoritative-empty → 60s). PHPStan flagged the redundant `$totalChunks > 0` guard - simplified (logins are non-empty past the early return).
- [x] Task 3: D3 fix - `useEmbedViewport()` via `useSyncExternalStore` on `(min-width: 640px)` gates the shared-embed render; `hidden sm:block` kept as paint guard; SSR snapshot true.
- [x] Task 4: `deferred-work.md` rewritten with the four outcomes; ledger states "Nothing remains deferred from story 7.7".
- [x] Task 5: AC2 sweep - api/src: 1 TODO (`TODO epic-32` allowlist comment, intentional); frontend/src: 0 TODO/FIXME/HACK; dead code from the 33.5-33.7 audits confirmed already removed in those stories; bounce ledger confirmed (AC2 list).
- [x] Task 6: Gates green (phpstan 0 with extensions, cs-fixer 0, arch OK, unit 558/558, full suite on `archilan_test_story338`, `pnpm gates` exit 0); PR opened; merge on green CI authorized.

## Dev Notes

- D1 surfaces: `api/src/Streaming/Application/TwitchApiClientInterface.php`, `api/src/Streaming/Infrastructure/TwitchApiClient.php` (token failure currently returns `[]` at the catch around `getAppToken`; per-chunk catch tolerates partial - count successful chunks), `api/src/Streaming/Infrastructure/NullTwitchApiClient.php`, `api/src/Streaming/Application/ParticipantStreamsView.php:118-129`, fakes in `api/tests/Unit/Streaming/TwitchStatusCheckerTest.php:54-72` and `api/tests/Functional/ParticipantStreamsTest.php:248-275`.
- D3 surface: `frontend/src/features/streaming/participant-streams.tsx:65-78` (matchMedia already used in the click handler at :67).
- PHPStan runs with phpstan-symfony/doctrine (33.6); the new nullable return must be annotated `array<string, int>|null`.
- 33.5's stricter arch gate applies; no layer moves here.
- Windows: use Edit tool / PowerShell literal replaces, never sed.

## Dev Agent Record

### Agent Model Used

Claude Fable 5 (claude-fable-5)

### Debug Log References

- PHPStan (with 33.6's extensions) caught a provably-redundant guard in the new outage detection - simplified before commit.
- Gates: phpstan 0, cs-fixer 0, arch OK, unit 558/558 (3 new), full isolated suite green, `pnpm gates` exit 0.

### Completion Notes List

- 4/4 deferred items closed: 2 fixed (D1 outage TTL, D3 embed unmount), 2 formally accepted (D2, D4) - `deferred-work.md` now records outcomes and states nothing remains from story 7.7.
- D1 behaviour change is exactly the approved one: a transient Twitch outage now self-heals in <=15s instead of 60s; authoritative results keep the 60s TTL and Helix quota profile.
- D3 behaviour change: shrinking below `sm` after selecting a channel now unmounts the iframe (no hidden network/audio activity); selection is preserved in state and the embed remounts when the viewport grows back.
- AC2: audit residue verified closed; non-trivial residues formally bounced (ClockInterface migration, AC-D5 setters, TanStack Query migration, typed SSE layer, Sessions TODO epic-32 items).

### File List

- `_bmad-output/implementation-artifacts/33-8-tech-debt-cleanup-and-deferred-item-triage.md` (this story), `deferred-work.md` (outcomes)
- `api/src/Streaming/Application/TwitchApiClientInterface.php` (contract `?array`)
- `api/src/Streaming/Infrastructure/TwitchApiClient.php` (null on token failure / all chunks failed)
- `api/src/Streaming/Application/ParticipantStreamsView.php` (15s outage TTL)
- `api/tests/Unit/Streaming/ParticipantStreamsViewTest.php` (new)
- `frontend/src/features/streaming/participant-streams.tsx` (useSyncExternalStore embed gate)

## Change Log

| Date | Change |
|------|--------|
| 2026-07-05 | Story created: 4-item triage table (2 fixes with designs grounded in the current code, 2 formal acceptances), AC2 bounce ledger consolidated from the 33.5-33.7 worklists. Status: ready-for-dev. |
| 2026-07-05 | Story executed: D1 + D3 fixed with tests, D2 + D4 formally accepted, deferred-work.md ledger closed, AC2 sweep clean. All gates green. Status → ready-for-review. |
