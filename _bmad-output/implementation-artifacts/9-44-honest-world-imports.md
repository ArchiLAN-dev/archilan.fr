# Story 9.44: Honest world imports - stop lying to apworlds about missing modules

Status: review
Related: 9.38 / 9.42 (preflight verdicts), 9.43 (structured failure record)

## Story

As an **admin uploading an apworld that works fine on desktop Archipelago**,
I want **it to load on the site too**,
so that **players get its YAML template and can actually pick the game**.

## Context - Castlevania: Aria of Sorrow

Reported 2026-08-01: the apworld uploads, but no YAML template is ever produced, so the
game is unusable. The same file works locally with the desktop software.

Root cause, verified step by step in the generation container:

1. The apworld is **not** at fault. It writes its data models against pydantic v1 and
   **ships its own vendored copy** (70 files), selected by
   `try: from pydantic.v1 import BaseModel / except ImportError: <vendored>` - explicitly
   designed for frozen Archipelago installs where pydantic cannot be installed.
2. Our loaders installed a **catch-all import finder**: any unknown module resolved to a
   stub. So the import never raised, the fallback became dead code, and `BaseModel` was a
   stub.
3. `Stub.__mro_entries__` returns `(object,)`, so `class RoutingInfo(BaseModel)` silently
   lost its base **and its `__init__`**. First instantiation raised
   `TypeError: RoutingInfo() takes no arguments`, the world never registered, and
   `generate_template.py` exited with "No game registered from the apworld(s)".
4. Desktop Archipelago has no such finder: the ImportError is real, the vendored copy
   loads, everything works. `worlds/__init__.py` upstream simply try/excepts each world and
   records failures in `failed_world_loads` - imports either really succeed, or the world
   is declared dead. There is no in-between where a module pretends to exist.

This is a **class** of bug, not one apworld: every world that handles a missing dependency
itself (vendored copy, graceful degradation) is broken the same way.

## Decision - why not a maintained denylist

The obvious fix (stub only an explicit list of client-only modules) was rejected by the
product owner for the right reason: that list changes whenever an apworld author changes
their dependencies, so it is stale by construction and we would be forever chasing it.

## Acceptance Criteria

**AC1 - Honest first:** the first import attempt runs with stubbing fully disabled,
regardless of what previous worlds needed, so behaviour never depends on load order and a
world shipping a fallback always gets a truthful `ImportError`.

**AC2 - Stub only what is proven missing:** on `ImportError`, the missing module is taken
from the exception itself, stubbed, and the import retried - repeatedly, bounded. Each
world converges to its own minimal stub set, computed at load time. **No list is
maintained anywhere.**

**AC3 - Clean retries:** a failed attempt rolls back `sys.modules` and the entries a
partial import added to `AutoWorldRegister`, so the retry starts from a clean state.

**AC4 - Archipelago roots stay fatal:** `BaseClasses`, `Options`, `worlds`… are never
stubbable; a failure there is a real failure.

**AC5 - All loaders share it:** `generate_multiworld`, `generate_template`,
`introspect_options` and `reachable` use the same module, so a world that loads for
generation also loads for reachability.

**AC6 - No regression:** every world that loaded before still loads, and what needed
stubbing is reported (`Note: stubbed missing module 'X' for Y.apworld`).

## Tasks / Subtasks

- [x] Task 1: `apworld_import.py` - shared honest-first loader (AC1-AC4).
- [x] Task 2: wire the four loaders + ship the module in site-packages (AC5).
- [x] Task 3: verify on the real pool (AC6) and on the reported apworld.

## Measured result (full local pool)

| | before | after |
|---|---|---|
| worlds loaded | 69 (Aria of Sorrow absent) | 70 (present) |
| worlds failing to load | 0 | 0 |
| modules stubbed | everything unknown, silently | 2 (`requests` for ffmq, `zilliandomizer` for zillion) |
| data packages skipped for stub contamination | guard needed | 0 |

The catch-all was masking far more than it rescued: worlds we believed depended on it
(KH2, The Wind Waker, Jak and Daxter, StarCraft 2) load with no stub at all.

After the change Aria of Sorrow produces its template, its 22 option types and its 120
locations, and a full multiworld generation still succeeds.

## Dev Notes

- Delivered in the archipelago repo (own git repo): PR #13. No api/frontend change.
- Ops: redeploy the archipelago image. Games whose template failed before keep an empty
  template in storage - **re-upload the apworld** (or "Importer depuis GitHub") to
  regenerate it. Nothing repairs them automatically.
- Follow-up worth considering: the same reasoning applies to `worlds/__init__.py`-style
  reporting - surfacing `failed_world_loads` in the admin UI would tell an admin which
  uploaded worlds are dead without reading container logs.

## Dev Agent Record

### Agent Model Used

Claude Fable 5 (claude-fable-5)

### Completion Notes List

- The diagnosis was requested before any fix; the causal chain was verified in the
  container (real template run, then an isolated reproduction of the stub-as-base-class
  mechanism) rather than inferred.
- `reachable.py` carried a fourth copy of the same machinery and was converted too, so the
  reachability daemon cannot silently disagree with the generator about which worlds exist.

### File List

- archipelago/apworld_import.py (new)
- archipelago/generate_multiworld.py
- archipelago/generate_template.py
- archipelago/introspect_options.py
- archipelago/reachable.py
- archipelago/Dockerfile
