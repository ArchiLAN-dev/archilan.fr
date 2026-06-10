# Story 9.21: Generation Container - Offline pip + Secret of Evermore Dependency

## Story

**As an** operator running multiworld generation,
**I want** the generation container to stop wasting time on doomed network pip
installs and to ship the Secret of Evermore dependency,
**So that** generation logs are clean, ~50s/gen of DNS-retry latency disappears,
and SoE seeds actually generate.

## Status

partial

> **Delivery status (2026-06-06):**
> - ✅ **Compose build-context scoping** delivered to `develop` via PR #15 (`c05c92a`): `archipelago-builder` → `./archipelago`, `bridge-builder` → `./bridge`.
> - ⏸️ **Deferred, local-only** (operator's decision - not pushed):
>   - archipelago `Dockerfile` offline pip + SoE `pyevermizer` - commit `d20cd3d` on `archilan-archipelago` `master`.
>   - archipelago `Dockerfile` CRLF strip - commit `5b6408e` (the `develop` submodule pointer instead relies on `.gitattributes eol=lf` at `a7246a4`).
>   - bridge `.dockerignore` - commit `2a97d2b` on the `bridge` repo `master`.
> - Resume by pushing those repo commits + bumping the submodule pointer when SoE/offline-pip is wanted in prod.

## Context

The generation container (`archipelago/Dockerfile`, in the **`archipelago` git
submodule** → repo `archilan-archipelago`, branch `master`) is sealed: no outbound
network, no `git`. Archipelago still tries to lazily `pip install` each world's
optional dependencies when loading its `.apworld`. In this sealed image those
installs fail noisily.

Observed in a 2026-06-06 generation log:

- **Harmless noise** - play-time/client deps of worlds that aren't needed to *roll*
  a seed: `factorio` (factorio-rcon-py), `jakanddaxter` (PyMemoryEditor), `kh2`
  (Pymem), `sc2` (nest-asyncio), `tww` (dolphin-memory-engine), `luigismansion`/
  `zillion` (`git+` deps). Each failed install spends ~8s on DNS retries → ~50s
  total per generation (11:21:10 → 11:22:01 in the log). Generation still
  succeeded (`AP_*.zip` produced).
- **Real defect** - `Warning: failed to load soe.apworld (soe): name '_loc' is not
  defined`: the Secret of Evermore world module fails to import because its
  generation-time dep `pyevermizer` is absent. SoE is missing from `worlds loaded`,
  so any seed including SoE would break.

Network must NOT be added to the gen container (determinism/isolation). The fix is
to (a) make the lazy installs fail fast and (b) bake the one *generation* dep that
matters at build time.

## Acceptance Criteria

**AC1:** `pip install pyevermizer==0.50.1` is baked into the gen image at build time, so `soe.apworld` loads and SoE appears in `worlds loaded`. (Wheel `cp313` manylinux - no compiler needed.)

**AC2:** Runtime pip is forced offline (`PIP_NO_INDEX=1`, `PIP_RETRIES=0`, `PIP_DEFAULT_TIMEOUT=1`) so the remaining worlds' lazy installs fail immediately instead of retrying over DNS. The env vars are placed **after** all build-time `pip install` steps so the build itself is unaffected.

**AC3:** `docker build archipelago/` succeeds; the produced image still generates a seed. The ~50s/gen retry latency is gone and the only remaining warnings are the instant "no matching distribution" / play-time deps.

## Tasks / Subtasks

- [x] Task 1: Add `RUN pip install --no-cache-dir pyevermizer==0.50.1` to `archipelago/Dockerfile` (after the core pip block).
- [x] Task 2: Add `ENV PIP_NO_INDEX=1 PIP_RETRIES=0 PIP_DEFAULT_TIMEOUT=1` after all build-time pip installs.
- [x] Task 3: Verify `pyevermizer==0.50.1` installs on `python:3.13-slim` (isolation: cp313 wheel, imports OK).
- [x] Task 4: Full `docker build archipelago/` succeeds (exit 0). Built image: `pyevermizer` importable, `PIP_NO_INDEX/RETRIES/DEFAULT_TIMEOUT` env confirmed at runtime.
- [ ] Task 5: Commit in the submodule (`archilan-archipelago` master), push (triggers ghcr publish), then bump the submodule pointer in the parent repo. **Push gated on user confirmation** (publishes a new image).

## Dev Notes

### Submodule + deploy side effect

`archipelago/` is a git submodule (own repo, `master`, no Gitflow/BMAD of its own).
Its CI publishes to `ghcr.io/archilan-dev/archipelago` on push to master - so
pushing is a deploy. Sequence: commit in submodule → push (→ image publish) →
parent records the new submodule SHA → commit pointer bump in parent.

Note: the parent working tree already carried an unrelated submodule pointer bump
(`014b3ad` → `a7246a4`) before this work; that is pre-existing, not part of this story.

### Why not strip the unsupported apworlds instead

Removing the noisy worlds' `.apworld` files would also silence the logs and is worth
considering if ArchiLAN only supports a curated set. Out of scope here - this story
keeps the full world set and only (a) speeds up the failures and (b) fixes the one
world (SoE) that genuinely fails to load.

## Compose build-context fixes (parent repo)

While building the full stack, both `bridge-builder` and `archipelago-builder` used
`context: .` (the monorepo root). Their Dockerfiles, however, use paths relative to
their own subdir:
- `archipelago/Dockerfile` was de-prefixed for the standalone submodule (`COPY entrypoint.sh`), so context=root failed: `"/entrypoint.sh": not found`.
- `bridge/Dockerfile` does `COPY requirements.txt` / `COPY . bridge/`, so context=root sent the **entire monorepo** (api, frontend, node_modules, .next, .git, submodules) as build context and copied it into the image.

Fix: scope each context to its subdir (`./archipelago`, `./bridge`) with
`dockerfile: Dockerfile`, plus a `bridge/.dockerignore` to keep caches/tests out of
the `COPY . bridge/` layer. Verified: `docker compose build archipelago-builder` and
`bridge-builder` both succeed; bridge context dropped to ~18 kB.

## File List

- `archipelago/Dockerfile` (submodule `archilan-archipelago`) - modified: bake `pyevermizer`, force offline runtime pip.
- `docker-compose.yml` (parent) - modified: scope `bridge-builder`/`archipelago-builder` build contexts to their subdirs.
- `bridge/.dockerignore` (parent) - new: exclude `__pycache__`, tests, caches from the bridge image layer.

## Change Log

| Date       | Change                                                                 |
|------------|------------------------------------------------------------------------|
| 2026-06-06 | Story created + implemented (Dockerfile in submodule). pyevermizer install verified in isolation; full image build + submodule push pending (push = ghcr publish, user-gated). |
| 2026-06-06 | Added parent-repo compose build-context fixes: `archipelago-builder` (`context: ./archipelago`) and `bridge-builder` (`context: ./bridge` + `bridge/.dockerignore`). Both `docker compose build` targets verified; bridge context shrank from the whole monorepo to ~18 kB. |
| 2026-06-06 | Runtime fix: `exec /ap_server.sh: no such file or directory` - scripts checked out on Windows (autocrlf) carried CRLF, breaking the shell shebang. The submodule `.gitattributes` enforces LF in the index but the working tree was stale CRLF, and Docker COPYs from the working tree. Added a build-time `sed -i 's/\r$//'` over the copied scripts in `archipelago/Dockerfile` (host-independent). Rebuilt + verified `/ap_server.sh` is LF and parses. |
