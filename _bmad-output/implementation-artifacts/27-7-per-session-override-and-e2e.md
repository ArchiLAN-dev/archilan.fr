# Story 27.7: Per-session override UI + end-to-end verification

Status: done

## Story

As an ArchiLAN admin,
I want to override the type profile for a specific session at creation/launch, and have an automated test
prove a configured option reaches the running AP server,
so that one-off needs are covered and the whole chain is guarded against regression.

## Context

Completes the epic: the per-session **override** UI (the persistence already exists from 27.2, the
resolver already merges it from 27.5) and an end-to-end assertion that a configured option is actually
applied on the launched server. Depends on 27.2, 27.5, 27.6 (and the deployed 27.3/27.4).

## Acceptance Criteria

1. Where each session type is created/launched by an admin, an optional **override** control set lets the
   admin change individual fields for that session only; unset fields inherit the type profile (per-field,
   matching the resolver). Applies to the relevant admin surfaces for private / event / weekly.
2. The override is persisted via the 27.2 override store and consumed by the resolver at launch (no new
   resolution logic - reuse 27.5).
3. The UI clearly shows which fields are overridden vs inherited (e.g. "hérité du profil" hint), and
   clearing an override returns the field to the profile value.
4. The weekly E2E smoke test (story 23.13, `scripts/e2e/weekly-smoke.sh`) is extended to: launch with a
   non-default `releaseMode` (e.g. `goal`) via the configured path and assert the launched AP server
   reports that mode (e.g. via the bridge room info / `releaseMode` field already read by
   `bridge/core/ap_client.py`, or AP server logs). Fails on a regression where the config is ignored.
5. Frontend gates green (typecheck/lint/build); the E2E smoke passes locally against the live stack.

## Tasks / Subtasks

- [x] Task 1 - Override API surface (if not already covered by 27.2): endpoint to set/clear a session
  override, admin-only; or fold into the existing create/launch admin actions.
- [x] Task 2 - Override UI controls on the admin launch/create surfaces for the three types, with
  inherited-vs-overridden affordance and clear-to-inherit (AC: 1, 3).
- [x] Task 3 - Confirm the resolver (27.5) picks up the override at launch for each type (integration
  check / functional test).
- [x] Task 4 - Extend `scripts/e2e/weekly-smoke.sh`: configure a non-default mode, launch, assert it on
  the running server (AC: 4). Update `scripts/e2e/README.md`.
- [x] Task 5 - Run frontend gates + the E2E smoke (AC: 5).

## Dev Notes

- **Verifying the mode on the running server:** `bridge/core/ap_client.py` already reads the room's
  `releaseMode`/`collectMode` (`_room_release_mode`/`_room_collect_mode`, exposed in its status payload).
  The smoke test can read the bridge `/status`-style endpoint or the member patch/room info to confirm
  the applied mode, rather than parsing AP logs. Confirm the exact field exposed.
- **Reuse, don't reinvent:** override persistence = 27.2 tables; merge = 27.5 resolver. This story is
  UI + test, plus a thin override-write endpoint if 27.2 didn't expose one.
- The override UI should be unobtrusive (collapsed "Options avancées" by default) so the common path
  (use the profile) stays one click.
- E2E: keep it bounded/idempotent like the existing smoke; gate behind the same `make e2e-weekly`.

### Project Structure Notes

- Frontend override controls live next to each type's admin launch action; the weekly one near the
  per-template generate/launch surfaces (stories 23.7/23.14).
- Test change in `scripts/e2e/` (no production `src/` changes for the E2E part).

### References

- [Source: _bmad-output/planning-artifacts/epic-27-configurable-session-server-options.md]
- [Source: _bmad-output/implementation-artifacts/27-2-session-config-persistence-admin-api.md (override store)]
- [Source: _bmad-output/implementation-artifacts/27-5-wire-config-into-gateways.md (resolver at launch)]
- [Source: _bmad-output/implementation-artifacts/23-13-weekly-run-e2e-smoke-test.md; scripts/e2e/weekly-smoke.sh]
- [Source: bridge/core/ap_client.py (_room_release_mode / _room_collect_mode)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

Override scope clarified by Jean: **weekly = per-template (admin-only)**, **event = per-session
(admin)**, **private = per-run (owner)** - members can't override. Shipped in 3 parts:

- **pt1 backend** (PR #64): resolver re-keyed by scope (template/session/run id); per-session snapshot
  dropped (scope-keyed overrides are stable). Override repo `delete()`; `Set`/`Clear`/`Query`
  services. Admin `GET/PUT/DELETE /admin/session-config/override/{scopeKey}`; owner
  `GET/PUT/DELETE /runs/{runId}/config-override` (`PersonalRunConfigOverride`, ownership-guarded).
- **pt2 override UI** (PR #66): shared `SessionConfigOverrideForm` (per-field "hériter/surcharger"
  toggle + clear-to-inherit) on the weekly template admin page, the event session admin detail page,
  and the private run owner page (gated on `run.isOwner`). Verified live.
- **pt3 E2E** (PR #67): `scripts/e2e/weekly-smoke.sh` sets a non-default template override
  (`releaseMode=enabled`) and asserts the launched ap-server got `RELEASE_MODE=enabled`. **Ran live -
  PASSED**.
- Related: clearer French wording on the config form (PR #65).

### File List

- api: SessionConfig Application (Set/Clear/Query/Resolver) + Domain/Infra override repo + admin
  override controller; PersonalRuns `PersonalRunConfigOverride` + controller; resolver call-sites in
  WeeklyRuns + Sessions.
- frontend: `session-config-override-form.tsx` + override helpers + 3 page integrations.
- `scripts/e2e/weekly-smoke.sh` (override proof).

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-09 | Story created from epic 27 plan (override UI + E2E). |
| 2026-06-09 | Implemented backend (PR #64) + override UIs (PR #66) + E2E proof (PR #67, ran live green). Model: weekly=template/admin, event=session/admin, private=run/owner. Status → done. |
