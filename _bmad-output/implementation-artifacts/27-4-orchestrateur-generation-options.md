# Story 27.4: Orchestrateur + archipelago — generation options (plando / race / spoiler)

Status: ready-for-dev

## Story

As the orchestrateur,
I want to accept `plando_options`, `race` and `spoiler` on a generation request and apply them when the
multiworld is generated,
so that admin-configured generation policy actually shapes the produced seed.

## Context

These three are **generation-time** options (Archipelago `generator` section), not server flags — so
they apply when `generate_multiworld.py` runs, not at server launch. Cross-repo:
`archilan-archipelago` (`generate_multiworld.py`) and `archilan-orchestrateur` (`GenerateRequest` +
the generate flow). Independent of 27.3.

## Acceptance Criteria

1. `generate_multiworld.py` accepts the three options (CLI args or env) and writes them into the
   generator config it uses (the `host.yaml`/generator settings consumed by the generation run):
   `plando_options` (comma list of `bosses|items|texts|connections`), `race` (0/1), `spoiler` (0|1|2|3).
   Verify the exact mechanism Archipelago's generator reads (host.yaml `generator:` vs `Generate.py`
   args) and set it accordingly.
2. Defaults when unset preserve current behaviour (today's effective generator defaults), so existing
   generations are unchanged.
3. orchestrateur `GenerateRequest` gains `PlandoOptions []string`, `Race bool`, `Spoiler int`; the
   generation path passes them to the container (env/args). Validate (unknown plando token / spoiler
   out of 0..3 → 400).
4. The generate API (`POST /sessions/{id}/generate`) accepts the new fields; swagger updated.
5. `race=1` correctly flags the seed as race mode (encrypted roms) — note any downstream impact on the
   weekly artifact/patches contract (story 23.12) and call it out if patches differ under race.
6. `go build/vet/test` green; archipelago image builds; requires redeploy of `archipelago:latest`.

## Tasks / Subtasks

- [ ] Task 1 (archipelago) — In `generate_multiworld.py`, read the three options and inject them into the
  generator config before invoking generation; default to current behaviour when unset. Confirm the
  authoritative read path in the bundled Archipelago generator.
- [ ] Task 2 (orchestrateur) — Extend `GenerateRequest` (internal/service) + the generate container
  invocation (internal/docker) to pass plando/race/spoiler as env/args; validate.
- [ ] Task 3 (orchestrateur) — API generate handler + type: accept `plandoOptions`, `race`, `spoiler`;
  swagger `@Param`/struct doc; 400 on invalid.
- [ ] Task 4 — `go build/vet/test`; PRs to both repos `master`; CI green.

## Dev Notes

- **Where generation reads these:** `generator.plando_options`, `generator.race`, `generator.spoiler`
  in `host.yaml` (see the host.yaml the user supplied). Confirm whether the project's
  `generate_multiworld.py` shells out to Archipelago `Generate.py` (which reads host.yaml/`generator:`)
  or sets them directly; inject at the right layer.
- **race interplay with weekly artifact:** story 23.12 made generation persist the whole `/data/output`
  as a flat zip (multidata + patches + spoiler). Under `race=1`, spoiler may be withheld and patches
  encrypted — verify the artifact still satisfies the launch/patch contract (23.10/23.12) and the E2E
  smoke (23.13); flag if `spoiler=0` removes the spoiler file the smoke expects.
- Precedent for threading a generate field exists: `GenerateRequest.Seed` (internal/service/session.go).
- Validate in orchestrateur and (27.1) in the api/ domain.

### Project Structure Notes

- Two separate repos (`master`). Deployable independently of 27.3; both feed 27.5.

### References

- [Source: _bmad-output/planning-artifacts/epic-27-configurable-session-server-options.md]
- [Source: archilan-archipelago generate_multiworld.py]
- [Source: archilan-orchestrateur internal/service/session.go GenerateRequest; internal/service/session.go runGeneration]
- [Source: _bmad-output/implementation-artifacts/23-12-full-generation-output-zip.md (artifact contract)]
- [Source: host.yaml generator section (user-supplied)]

## Dev Agent Record

### Agent Model Used

### Debug Log References

### Completion Notes List

### File List

## Change Log

| Date       | Change |
|------------|--------|
| 2026-06-09 | Story created from epic 27 plan (generation options). |
