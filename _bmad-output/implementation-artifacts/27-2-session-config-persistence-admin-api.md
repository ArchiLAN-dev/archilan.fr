# Story 27.2: Session config persistence + admin API + resolution service

Status: ready-for-dev

## Story

As an ArchiLAN admin,
I want to read and edit the server/generation config profile for each session type, persisted server-side,
so that the configured values drive every launched run, and I can revisit/change them over time.

## Context

Builds on the domain model from story 27.1. Adds persistence for the three **type profiles**
(private / event / weekly), storage for optional **per-session overrides**, an admin-only API to
read/update a profile, and a **resolution service** that computes the effective config
(`profile ⊕ override`) used by the gateways in 27.5. Depends on 27.1.

## Acceptance Criteria

1. Migration creates a `session_config_profiles` table keyed by session type (`private|event|weekly`),
   one row per type, storing the server + generation options (columns or a validated JSON blob — see
   Dev Notes). Migration is reversible (`down()` drops it) and seeds the three rows with the 27.1 defaults.
2. Per-session override is persisted (a `session_config_overrides` table keyed by the external session
   id, nullable per field) — created here even though the override **UI** lands in 27.7, so the
   resolution service has a real source.
3. `GET /api/v1/admin/session-config/{type}` returns the profile for a type (admin-only via
   `ApiAccessGuard::requireAdmin`); 404 on unknown type.
4. `PUT /api/v1/admin/session-config/{type}` validates the body **through the 27.1 domain VOs** (invalid
   → 422 with the domain error code) and persists it; returns the saved profile. Admin-only.
5. A `SessionConfigResolver` application service exposes `resolve(type, ?sessionId): EffectiveConfig`
   returning the type profile merged with the session override (per-field). Pure orchestration over the
   repo + domain; no DBAL/EM injected into Application (api/CLAUDE.md AC-A2 — use a repository interface
   in Domain + a query interface in Application, DBAL impl in Infrastructure).
6. The resolved config can be persisted per session at launch time (so a crashed-session restart reuses
   it rather than reverting to defaults — addresses the epic's restart-note). Expose a
   `recordResolvedForSession(sessionId, EffectiveConfig)` path used by 27.5.
7. Functional tests: GET/PUT happy paths + auth (non-admin → 401/403), PUT validation (422 on bad enum),
   and resolver merge (profile only; profile + override).
8. Quality gates green (phpstan, php-cs-fixer, phpunit, `app:architecture:ddd`).

## Tasks / Subtasks

- [ ] Task 1 — Migration `Version{ts}.php`: `session_config_profiles` (+ seed 3 rows) and
  `session_config_overrides` (AC: 1, 2). Follow api/CLAUDE.md migration standards (timestamp +1s, reversible).
- [ ] Task 2 — Domain repository interface (`SessionConfigProfileRepositoryInterface`) + Doctrine impl;
  Application query interface for reads + DBAL impl (AC-A2). Map JSON ⇄ 27.1 VOs.
- [ ] Task 3 — `AdminSessionConfigQuery` (read) + `AdminUpdateSessionConfig` (write, validates via VOs)
  application services (AC: 3, 4).
- [ ] Task 4 — Controllers `AdminSessionConfigController` (GET) + `AdminUpdateSessionConfigController`
  (PUT), admin-only, error mapping (422 `invalid_*`, 404 `unknown_type`) (AC: 3, 4).
- [ ] Task 5 — `SessionConfigResolver` + `recordResolvedForSession` (AC: 5, 6).
- [ ] Task 6 — Functional tests (`WebTestCase`, schema per class) + run gates (AC: 7, 8).

## Dev Notes

- **Storage shape:** a JSON blob column per profile is simplest and matches the VO set; validate on the
  way in via 27.1 VOs (never trust the column). If columns are preferred for queryability, mirror the
  default table from 27.1. JSON recommended (the data is read whole, never filtered).
- **Layering (api/CLAUDE.md):** Application must not inject `Connection`/`EntityManagerInterface`
  (AC-A1/A2). Entity ops → Domain repo interface; reads → Application query interface; DBAL impl in
  Infrastructure. Controllers: deserialize → validate → one Application call → serialize (AC-P3/P4).
- **Auth:** `ApiAccessGuard::requireAdmin($request)` (same pattern as the weekly admin controllers, e.g.
  `AdminGenerateWeeklyRunForTemplateController` from story 23.14). Error envelope via
  `ApiAccessGuard::errorResponse(code, msg, status)`.
- **Type enum** (`private|event|weekly`) should be a small domain enum reused by the resolver and routes.

### Project Structure Notes

- Lives in the `SessionConfig` context from 27.1 (Application + Infrastructure + Presentation layers).
- No frontend here (form is 27.6, override UI is 27.7) — but the API contract defined here is what 27.6 consumes.

### References

- [Source: _bmad-output/planning-artifacts/epic-27-configurable-session-server-options.md]
- [Source: _bmad-output/implementation-artifacts/27-1-session-config-domain.md]
- [Source: api/src/WeeklyRuns/Presentation/Admin/AdminGenerateWeeklyRunForTemplateController.php (admin controller + error mapping pattern)]
- [Source: api/CLAUDE.md#Application layer + #Migration standards]

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-09 | Story created from epic 27 plan (persistence + admin API + resolver). |
