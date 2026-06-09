# Story 27.8: orchestrateur-client — forward session options (generate/launch)

Status: review

## Story

As the `api/` integration of epic 27,
I want the `archilan/orchestrateur-client` package to forward optional server & generation options
on `generate`, `launch` and `launchFromFile`,
so that the session-config (27.1/27.2) can actually reach the orchestrateur (story 27.5).

## Context

Per `packages/CLAUDE.md`, a package is a black box: a gap found during integration becomes a
**dedicated story + separate PR** on the package repo. Wiring the resolved config into the gateways
(27.5) revealed that `SessionsClient::generate/launch/launchFromFile` accept only seed/passwords —
they cannot forward the new `server_options` (27.3) / generation options (27.4) the orchestrateur
now understands. This story closes that gap so 27.5 can adapt to the package (never the reverse).

## Acceptance Criteria

1. `SessionsClient::generate(sessionId, adminPassword, ?seed, array $generationOptions = [])` merges
   `$generationOptions` into the JSON body (native types — `plandoOptions: list<string>`, `race: bool`,
   `spoiler: int`). Backward compatible (default `[]` = unchanged body).
2. `SessionsClient::launch(sessionId, adminPassword, ?serverPassword, array $serverOptions = [])` merges
   `$serverOptions` into the JSON body. Backward compatible.
3. `SessionsClient::launchFromFile(..., array $serverOptions = [])` appends each option as a
   **string** multipart form field (bool → `"true"`/`"false"`, int → decimal string, string as-is),
   matching the orchestrateur's form parsing. Backward compatible.
4. The client stays **agnostic of specific keys** — it forwards whatever the caller passes (the
   consumer owns the mapping, e.g. the join-password ↔ `serverPassword` decision).
5. Package quality gates green: PHPStan level 9 (src + tests), PHPUnit ^11. New tests assert each
   method forwards the options (body/form capture via `MockHttpClient`).
6. Version bumped to **1.1.0** (additive, backward-compatible); tag pushed so `api/` can
   `composer update archilan/orchestrateur-client`.

## Tasks / Subtasks

- [x] Task 1 — Extend `generate`/`launch` (JSON merge) + `launchFromFile` (multipart stringify) with
  the optional options arrays (AC: 1–4).
- [x] Task 2 — Tests in `tests/Sessions/SessionsClientTest.php` capturing the forwarded body/form
  (AC: 5).
- [x] Task 3 — PHPStan level 9 + PHPUnit green; bump `composer.json` version to 1.1.0; PR to `master`;
  tag `v1.1.0` (AC: 5, 6).

## Dev Notes

- Repo: `packages/orchestrateur-client` (separate git → `archilan-orchestrateur-client`, master-only).
- Gates are the **package's** (PHPStan 9, PHPUnit ^11) — `api/` gates do not apply (packages/CLAUDE.md).
- Multipart values must be strings: add a small `toFormValue(scalar): string` helper.
- Consumer mapping (27.5): the config's `joinPassword` is passed via the existing `$serverPassword`
  argument; `toServerFlags()`'s `password` key is dropped by the gateway before building
  `$serverOptions` (the client does not special-case it).

### References

- [Source: packages/CLAUDE.md (package change cycle)]
- [Source: _bmad-output/implementation-artifacts/27-5-wire-config-into-gateways.md]
- [Source: _bmad-output/implementation-artifacts/27-1-session-config-domain.md (toServerFlags/toGenerationParams)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code).

### Completion Notes List

### File List

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-09 | Story created (package gap extracted from 27.5 per packages governance). |
