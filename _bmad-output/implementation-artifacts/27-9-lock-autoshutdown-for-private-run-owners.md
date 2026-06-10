# Story 27.9: lock the inactivity auto-shutdown for private-run owners

Status: review

## Story

As an administrator of ArchiLAN,
I want the **inactivity auto-shutdown** (`autoShutdown`) to be configurable only on the
admin-managed "Sessions privées" type profile (the template),
so that private-run owners cannot change it from their per-run override — it is a
platform-resource concern, not a per-player setting.

## Context

Epic 27 gives three override scopes (see `SessionConfigResolver`): weekly = per-template
(admin-only), event = per-session (admin-only), **private = per-run (owner-managed)** via
`PersonalRunConfigOverride`. The owner override form (`SessionConfigOverrideForm`, shared with the
admin scopes) currently exposes every field, including `autoShutdown` ("Arrêt auto après
inactivité (s)").

`autoShutdown` controls when the inactivity watchdog (epic 17) stops an idle AP server to free
platform resources. It must stay an operator decision: admins set it on the **private type profile**
(`admin/session-config`, type "Sessions privées"), and every private run inherits it. Run owners
should see the rest of the override panel but must not be able to override `autoShutdown`.

Enforcement must be authoritative, not cosmetic. Owners change config *only* through
`PersonalRunConfigOverride::set`; the resolver applies whatever is stored for the run's scope key.
Stripping `autoShutdown` at that write path means it can never be persisted from the owner side, so
the resolved value always falls back to the admin profile.

## Acceptance Criteria

1. `PersonalRunConfigOverride::set` strips the `autoShutdown` key from the incoming override before
   delegating to `SetSessionConfigOverride` — an owner cannot persist it (no error; the field is
   silently ignored, the rest of the override is saved).
2. `PersonalRunConfigOverride::get` does not echo a (legacy) stored `autoShutdown` value back to the
   owner, so the field is never re-surfaced from stale data.
3. The admin scopes are unaffected: `AdminSessionConfigOverrideController` (weekly template / event
   session) still accepts `autoShutdown`, and the type-profile editor still sets it for "Sessions
   privées".
4. Frontend: `SessionConfigOverrideForm` accepts an optional `lockedKeys` prop; locked fields are not
   rendered (no toggle, no inherited-value line). The private-run panel
   (`personal-run-detail-page.tsx`) passes `lockedKeys={["autoShutdown"]}`; admin usages pass nothing
   (unchanged).
5. Quality gates green: API (`phpstan`, `php-cs-fixer`, `phpunit`, `app:architecture:ddd`),
   Frontend (`typecheck`, `lint`, `build`).

## Tasks / Subtasks

- [x] Task 1 — API: strip locked fields in `PersonalRunConfigOverride` (AC 1, 2).
  - [x] `OWNER_LOCKED_FIELDS = ['autoShutdown']`; filter the array on `set`; filter the returned
        override on `get`.
- [x] Task 2 — Frontend: `lockedKeys` prop on `SessionConfigOverrideForm`; filter `FIELDS`; wire
      `["autoShutdown"]` from the private-run panel (AC 4).
- [x] Task 3 — Tests: unit test that an owner's `autoShutdown` is dropped while sibling fields persist;
      existing tests stay green (AC 1, 5).

## Dev Notes

### Project Structure Notes

- `api/src/PersonalRuns/Application/PersonalRunConfigOverride.php` — owner-scoped application service;
  the only write path for private-run overrides. Locking lives here (PersonalRuns Application owns the
  owner policy), not in `SessionConfig`, which stays scope-agnostic.
- `frontend/src/features/admin/session-config-override-form.tsx` — shared editor (admin + owner).
- `frontend/src/features/personal-runs/personal-run-detail-page.tsx` — owner panel wiring.

### References

- `SessionConfigResolver` — applies the stored override for a scope key; no owner/admin distinction,
  hence enforcement at the owner write path.
- Epic 17 — inactivity watchdog (`InactivityWatchdogHandler`) consumes `autoShutdown`.
- Pre-existing data: the owner override UI is recent (≤ v0.1.0) and `autoShutdown` overrides on
  private runs are not expected in the wild; no data migration. AC 2 defends against any stale value.

## Dev Agent Record

- Enforced at the owner application service (write + read), mirroring the membership-gate principle:
  the authoritative gate is server-side; the UI hide is a convenience.

## Change Log

- 2026-06-10 — Story created and implemented (status: review).
