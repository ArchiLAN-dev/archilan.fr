#!/usr/bin/env python3
"""Headless reachability checker using AP's logic engine.

Usage:
    python reachable.py \
        --archipelago /path/to/AP_xxx.archipelago \
        --yamls      /path/to/yamls/ \
        --apsave     /path/to/AP_xxx.apsave \
        --slot       1

Outputs JSON to stdout:
{
  "reachable":  [{"id": 123, "name": "..."}],
  "checked":    [{"id": 123, "name": "..."}],
  "unreachable": [{"id": 123, "name": "..."}],
  "items_received": [{"id": 123, "name": "...", "count": 2}]
}
"""
from __future__ import annotations

import argparse
import importlib.abc
import importlib.machinery
import json
import json as _json
import logging
import pathlib
import pickle
import sys
import types
import warnings
import zipfile
import zlib
import glob
import os
from collections import Counter
from pathlib import Path

# ---------------------------------------------------------------------------
# Paths
# ---------------------------------------------------------------------------

AP_SRC = "/app/ArchipelagoSrc"
OFFICIAL_APWORLDS = pathlib.Path("/app/Archipelago/Archipelago/lib/worlds")
APWORLDS_IN = pathlib.Path("/apworlds")            # prod: per-session copy
APWORLDS_DEV = pathlib.Path("/arch_workspace/apworlds")  # dev: workspace volume

if AP_SRC not in sys.path:
    sys.path.insert(0, AP_SRC)

# ---------------------------------------------------------------------------
# Pre-stubs (must run before any AP import)
# ---------------------------------------------------------------------------

_mu = types.ModuleType("ModuleUpdate")
_mu.update = lambda *_, **__: None  # type: ignore[attr-defined]
sys.modules["ModuleUpdate"] = _mu

_winapi_stub = types.ModuleType("_winapi")
_winapi_stub.__getattr__ = lambda name: 0  # type: ignore[method-assign]
sys.modules["_winapi"] = _winapi_stub

_orjson = types.ModuleType("orjson")
_orjson.loads = _json.loads  # type: ignore[attr-defined]
_orjson.dumps = lambda obj, **kw: _json.dumps(obj, default=str).encode()  # type: ignore[attr-defined]
sys.modules["orjson"] = _orjson

# ---------------------------------------------------------------------------
# Auto-stub: silence client-only third-party imports (mirrors generate_multiworld.py)
# ---------------------------------------------------------------------------

_ARCHIP_ROOTS = frozenset({
    "BaseClasses",
    "entrance_rando",
    "Fill",
    "Generate",
    "Main",
    "MultiServer",
    "NetUtils",
    "Options",
    "Patch",
    "settings",
    "Utils",
    "WebHost",
    "worlds",
})


class _Stub:
    def __getattr__(self, _n): return _Stub()
    def __call__(self, *a, **kw): return _Stub()
    def __getitem__(self, key): return _Stub()
    def __setitem__(self, key, value): pass
    def __delitem__(self, key): pass
    def __contains__(self, item): return False
    def __neg__(self): return _Stub()
    def __pos__(self): return _Stub()
    def __abs__(self): return _Stub()
    def __invert__(self): return _Stub()
    def __add__(self, o): return _Stub()
    def __radd__(self, o): return _Stub()
    def __sub__(self, o): return _Stub()
    def __rsub__(self, o): return _Stub()
    def __mul__(self, o): return _Stub()
    def __rmul__(self, o): return _Stub()
    def __truediv__(self, o): return _Stub()
    def __rtruediv__(self, o): return _Stub()
    def __floordiv__(self, o): return _Stub()
    def __rfloordiv__(self, o): return _Stub()
    def __mod__(self, o): return _Stub()
    def __rmod__(self, o): return _Stub()
    def __pow__(self, o, m=None): return _Stub()
    def __rpow__(self, o): return _Stub()
    def __matmul__(self, o): return _Stub()
    def __rmatmul__(self, o): return _Stub()
    def __and__(self, o): return _Stub()
    def __rand__(self, o): return _Stub()
    def __or__(self, o): return _Stub()
    def __ror__(self, o): return _Stub()
    def __xor__(self, o): return _Stub()
    def __rxor__(self, o): return _Stub()
    def __lshift__(self, o): return _Stub()
    def __rlshift__(self, o): return _Stub()
    def __rshift__(self, o): return _Stub()
    def __rrshift__(self, o): return _Stub()
    def __lt__(self, o): return False
    def __le__(self, o): return False
    def __gt__(self, o): return False
    def __ge__(self, o): return False
    def __eq__(self, o): return isinstance(o, _Stub)
    def __ne__(self, o): return not isinstance(o, _Stub)
    def __bool__(self): return False
    def __int__(self): return 0
    def __float__(self): return 0.0
    def __complex__(self): return 0j
    def __index__(self): return 0
    def __str__(self): return ""
    def __repr__(self): return "stub"
    def __bytes__(self): return b""
    def __hash__(self): return 0
    def __iter__(self): return iter([])
    def __len__(self): return 0
    def items(self): return {}.items()
    def values(self): return {}.values()
    def keys(self): return {}.keys()


class _AutoStubFinder(importlib.abc.MetaPathFinder, importlib.abc.Loader):
    def find_spec(self, fullname: str, path, target=None):
        if fullname.split(".")[0] in _ARCHIP_ROOTS:
            return None
        return importlib.machinery.ModuleSpec(fullname, self)

    def create_module(self, spec):
        return types.ModuleType(spec.name)

    def exec_module(self, module):
        module.__getattr__ = lambda _n: _Stub()


sys.meta_path.append(_AutoStubFinder())

# ---------------------------------------------------------------------------
# AP imports (after stubs and sys.path setup)
# ---------------------------------------------------------------------------

warnings.filterwarnings("ignore")  # silence _speedups warning

from BaseClasses import CollectionState, MultiWorld, ItemClassification  # noqa: E402
from worlds import AutoWorld  # noqa: E402
import worlds as _worlds_pkg  # noqa: E402
from worlds.generic.Rules import exclusion_rules  # noqa: E402
from NetUtils import NetworkItem  # noqa: E402

logging.basicConfig(level=logging.ERROR)  # suppress AP generator noise

# ---------------------------------------------------------------------------
# Apworld loading (mirrors generate_multiworld.py)
# ---------------------------------------------------------------------------

def _load_apworlds_from(apworld_dir: pathlib.Path) -> None:
    if not apworld_dir.is_dir():
        return
    for apw in sorted(apworld_dir.glob("*.apworld")):
        try:
            with zipfile.ZipFile(str(apw)) as zf:
                entries = zf.namelist()
                pkg = entries[0].split("/")[0] if entries else None
        except Exception as e:
            print(f"Warning: could not inspect {apw.name}: {e}", file=sys.stderr)
            continue
        if not pkg or not pkg.isidentifier():
            print(f"Warning: skipping {apw.name}: invalid package name", file=sys.stderr)
            continue
        mod = f"worlds.{pkg}"
        if mod in sys.modules:
            continue
        _worlds_pkg.__path__.append(str(apw))
        try:
            importlib.import_module(mod)
        except Exception as e:
            _worlds_pkg.__path__.remove(str(apw))
            print(f"Warning: failed to load {apw.name} ({pkg}): {e}", file=sys.stderr)


# Load official apworlds then custom apworlds (prod: /apworlds, dev: /arch_workspace/apworlds)
_load_apworlds_from(OFFICIAL_APWORLDS)
_load_apworlds_from(APWORLDS_IN)
_load_apworlds_from(APWORLDS_DEV)

# Rebuild network_data_package to include late-loaded worlds
_worlds_pkg.network_data_package["games"].update({
    cls.game: cls.get_data_package_data()
    for cls in _worlds_pkg.AutoWorldRegister.world_types.values()
})

# ---------------------------------------------------------------------------
# Save helpers (same as bridge)
# ---------------------------------------------------------------------------

def _slot_map(mapping: dict) -> dict:
    result = {}
    for key, val in mapping.items():
        if isinstance(key, tuple) and len(key) >= 2 and key[0] == 0:
            slot = int(key[1])
            if slot in result:
                existing = result[slot]
                if isinstance(existing, list) and isinstance(val, list):
                    result[slot] = existing + val
                elif isinstance(existing, (set, frozenset)) and isinstance(val, (set, frozenset)):
                    result[slot] = existing | val
            else:
                result[slot] = val
        elif isinstance(key, int):
            result[key] = val
    return result


def load_apsave(path: str) -> dict:
    with open(path, "rb") as f:
        return pickle.loads(zlib.decompress(f.read()))


def load_archipelago(path: str) -> dict:
    with open(path, "rb") as f:
        return pickle.loads(zlib.decompress(f.read()[1:]))


# ---------------------------------------------------------------------------
# Fake AP generation
# ---------------------------------------------------------------------------

def build_multiworld(game: str, player_name: str, yaml_path: str, slot_data: dict) -> tuple[MultiWorld, int]:
    """Regenerate a minimal MultiWorld (rules only, no item placement)."""
    from Generate import main as GMain, mystery_argparse

    sys.argv = [sys.argv[0]]
    args = mystery_argparse()
    args.player_files_path = str(Path(yaml_path).parent)
    args.skip_output = True
    args.multi = 0
    args.log_level = "error"

    g_args, seed = GMain(args)

    # Find our slot in the generated args
    player_id = next((p for p, n in g_args.name.items() if n == player_name), 1)

    g_args.multi = 1
    g_args.game = {1: game}
    g_args.name = {1: player_name}
    g_args.player_ids = {1}

    # Copy the player's options onto slot 1
    for attr in vars(g_args):
        val = getattr(g_args, attr)
        if isinstance(val, dict) and player_id in val and player_id != 1:
            val[1] = val[player_id]

    gen_steps = [s for s in (
        "generate_early", "create_regions", "create_items",
        "set_rules", "connect_entrances", "generate_basic",
    ) if hasattr(AutoWorld.World, s)]

    mw = MultiWorld(1)
    mw.generation_is_fake = True
    mw.re_gen_passthrough = {game: slot_data} if slot_data else {}
    mw.set_seed(seed, g_args.race, str(g_args.outputname) if g_args.outputname else None)
    mw.game = {1: game}
    mw.player_name = {1: player_name}
    mw.set_options(g_args)
    mw.state = CollectionState(mw)

    for step in gen_steps:
        AutoWorld.call_all(mw, step)
        if step == "set_rules":
            exclusion_rules(mw, 1, mw.worlds[1].options.exclude_locations.value)
        if step == "generate_basic":
            break

    # Do NOT clear mw.precollected_items[1].
    # Starting items (e.g. Poltergust 3000 in Luigi's Mansion) are precollected at
    # generation time and are NOT sent via the AP received_items protocol, so they
    # would be absent from our in-memory state if we cleared them.
    # CollectionState(mw) auto-collects them with event=False (updates reachable_regions).
    # received_items are then collected on top - double-collecting a progression item
    # is harmless for boolean has() checks.

    return mw, 1


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--archipelago", required=True)
    parser.add_argument("--yamls", required=True)
    parser.add_argument("--apsave", required=False, default=None)
    parser.add_argument("--slot", type=int, default=1)
    parser.add_argument(
        "--daemon", action="store_true",
        help="Persistent mode: read JSON requests from stdin, write JSON results to stdout",
    )
    args = parser.parse_args()

    # ── One-time setup (expensive) ────────────────────────────────────────────

    arch = load_archipelago(args.archipelago)

    slot_info = arch["slot_info"]
    slot = args.slot
    net_slot = slot_info.get(slot)
    if net_slot is None:
        print(json.dumps({"error": f"slot {slot} not found"}), file=sys.stderr)
        sys.exit(1)

    game: str = net_slot.game
    player_name: str = net_slot.name
    slot_data: dict = arch.get("slot_data", {}).get(slot, {})

    dp = arch.get("datapackage", {}).get(game, {})
    id_to_loc = {v: k for k, v in dp.get("location_name_to_id", {}).items()}
    id_to_item: dict[int, str] = {}
    for _gdata in arch.get("datapackage", {}).values():
        for _iname, _iid in _gdata.get("item_name_to_id", {}).items():
            id_to_item[_iid] = _iname
    slot_names: dict[int, str] = {s: ns.name for s, ns in slot_info.items()}
    arch_locs: dict[int, tuple] = arch.get("locations", {}).get(slot, {})

    # Items expected for this slot - static, computed once from seed
    expected_counter: Counter = Counter()
    for _slot_locs in arch.get("locations", {}).values():
        for _item_id, _recv_slot, _flags in _slot_locs.values():
            if _recv_slot == slot and _item_id > 0:
                expected_counter[id_to_item.get(_item_id, f"#{_item_id}")] += 1

    raw_spheres = arch.get("spheres", [])

    yaml_candidates = list(Path(args.yamls).glob(f"{player_name}.yaml"))
    if not yaml_candidates:
        yaml_candidates = list(Path(args.yamls).glob("*.yaml"))
    if not yaml_candidates:
        print(json.dumps({"error": f"no yaml found in {args.yamls}"}), file=sys.stderr)
        sys.exit(1)
    yaml_path = str(yaml_candidates[0])

    mw, player_id = build_multiworld(game, player_name, yaml_path, slot_data)
    # Prefer the session's own datapackage for ID→name resolution: it matches the IDs
    # in received_items exactly (same generation). The rebuilt world's item_id_to_name
    # can diverge if the apworld was updated after the session was created.
    _arch_id_to_name: dict[int, str] = {
        v: k for k, v in dp.get("item_name_to_id", {}).items()
    }
    _world_id_to_name: dict[int, str] = mw.worlds[player_id].item_id_to_name
    item_id_to_name: dict[int, str] = {**_world_id_to_name, **_arch_id_to_name}
    event_locations = [loc for loc in mw.get_locations(player_id) if not loc.address]

    # ── Per-request computation (fast once multiworld is loaded) ──────────────

    def _compute(checked_ids: set[int], received_items: list) -> dict:
        """Compute reachability from in-memory state.

        checked_ids: set of checked location IDs for this slot.
        received_items: list of [item_id, sender_slot, location_id] tuples/lists.
        """
        missing_ids = set(arch_locs.keys()) - checked_ids

        cs = CollectionState(mw)
        item_counts: Counter = Counter()
        for entry in received_items:
            item_id = entry[0] if isinstance(entry, (list, tuple)) else (entry.item if hasattr(entry, "item") else 0)
            if item_id <= 0 or item_id not in item_id_to_name:
                continue
            name = item_id_to_name[item_id]
            world_item = mw.create_item(name, player_id)
            cs.collect(world_item)
            item_counts[name] += 1

        cs.sweep_for_advancements(locations=event_locations)

        reachable_ids: set[int] = {
            loc.address
            for loc in mw.get_reachable_locations(cs, player_id)
            if loc.address is not None and not isinstance(loc.address, list)
        }

        def loc_entry(loc_id: int) -> dict:
            name = id_to_loc.get(loc_id, f"#{loc_id}")
            item_id_l, recv_slot, flags = arch_locs.get(loc_id, (0, slot, 0))
            return {
                "id": loc_id,
                "name": name,
                "item": {
                    "id": item_id_l,
                    "name": id_to_item.get(item_id_l, f"#{item_id_l}"),
                    "flags": flags,
                    "slot": recv_slot,
                    "slot_name": slot_names.get(recv_slot, f"Slot {recv_slot}"),
                },
            }

        reachable_unchecked = [loc_entry(i) for i in reachable_ids if i in missing_ids]
        reachable_checked   = [loc_entry(i) for i in reachable_ids if i in checked_ids]
        unreachable         = [loc_entry(i) for i in missing_ids if i not in reachable_ids]
        checked_not_reach   = [loc_entry(i) for i in checked_ids if i not in reachable_ids]

        items_out = [
            {"id": dp.get("item_name_to_id", {}).get(name, 0), "name": name, "count": count}
            for name, count in item_counts.most_common()
        ]

        not_received_counter = expected_counter - item_counts
        items_not_received_out = [
            {"id": dp.get("item_name_to_id", {}).get(name, 0), "name": name, "count": count}
            for name, count in not_received_counter.most_common()
        ]

        def sphere_loc_entry(loc_id: int) -> dict:
            entry = loc_entry(loc_id)
            if loc_id in checked_ids:
                entry["check_status"] = "checked"
            elif loc_id in reachable_ids:
                entry["check_status"] = "reachable"
            else:
                entry["check_status"] = "blocked"
            return entry

        spheres_out = []
        for _i, _sphere in enumerate(raw_spheres):
            _ids = sorted(_sphere.get(slot, set()))
            if not _ids:
                continue
            _s_checked = [_l for _l in _ids if _l in checked_ids]
            _s_reach   = [_l for _l in _ids if _l in reachable_ids and _l not in checked_ids]
            _s_future  = [_l for _l in _ids if _l not in checked_ids and _l not in reachable_ids]
            if len(_s_checked) == len(_ids):
                _status = "past"
            elif _s_reach:
                _status = "current"
            else:
                _status = "future"
            spheres_out.append({
                "index": _i,
                "status": _status,
                "counts": {
                    "total": len(_ids),
                    "checked": len(_s_checked),
                    "reachable": len(_s_reach),
                    "blocked": len(_s_future),
                },
                "locations": [sphere_loc_entry(_l) for _l in _ids],
            })

        return {
            "game": game,
            "player": player_name,
            "reachable_unchecked": reachable_unchecked,
            "reachable_checked": reachable_checked,
            "unreachable_unchecked": unreachable,
            "checked_unreachable": checked_not_reach,
            "items_received": items_out,
            "items_not_received": items_not_received_out,
            "spheres": spheres_out,
            "counts": {
                "checked": len(checked_ids),
                "total": len(arch_locs),
                "reachable_now": len(reachable_unchecked),
            },
        }

    # ── Run mode ──────────────────────────────────────────────────────────────

    if args.daemon:
        # Signal readiness, then serve requests from stdin indefinitely.
        # Request: {"checked_locations": [...], "received_items": [[id,sender,loc], ...]}\n
        # Response: {result JSON}\n
        print(json.dumps({"ready": True}), flush=True)
        for line in sys.stdin:
            line = line.strip()
            if not line:
                continue
            try:
                req = json.loads(line)
                checked = set(req.get("checked_locations", []))
                ri = req.get("received_items", [])
                result = _compute(checked, ri)
                print(json.dumps(result, ensure_ascii=False), flush=True)
            except Exception as exc:
                print(json.dumps({"error": str(exc)}), flush=True)
    else:
        # One-shot mode: read state from stdin (piped by bridge) or fall back to --apsave.
        checked_ids: set[int] = set()
        received_items: list = []
        state_from_stdin = False
        if not sys.stdin.isatty():
            line = sys.stdin.readline().strip()
            if line:
                try:
                    req = json.loads(line)
                    checked_ids = set(req.get("checked_locations", []))
                    received_items = req.get("received_items", [])
                    state_from_stdin = True
                except (json.JSONDecodeError, Exception):
                    pass
        if not state_from_stdin and args.apsave and os.path.isfile(args.apsave):
            save = load_apsave(args.apsave)
            loc_checks = _slot_map(save.get("location_checks", {}))
            checked_ids = set(loc_checks.get(slot, set()))
            ri_map = _slot_map(save.get("received_items", {}))
            received_items = ri_map.get(slot, [])
        print(json.dumps(_compute(checked_ids, received_items), ensure_ascii=False))


if __name__ == "__main__":
    main()
