#!/usr/bin/env python3
"""Read the latest .apsave file and emit JSON to stdout.

Runs inside an Archipelago container where NetUtils and AP classes are available.
"""
from __future__ import annotations

import argparse
import glob
import json
import os
import pickle
import sys
import zlib

_ap_src = "/app/ArchipelagoSrc"
if os.path.isdir(_ap_src) and _ap_src not in sys.path:
    sys.path.insert(0, _ap_src)


def _save_slot_map(mapping: dict) -> dict:
    result: dict = {}
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


def _extract_hint(h: object) -> dict:
    status_raw = getattr(h, "status", 0)
    status_val = status_raw.value if hasattr(status_raw, "value") else int(status_raw)
    if status_val == 0 and getattr(h, "found", False):
        status_val = 40
    return {
        "receiving_player": int(getattr(h, "receiving_player", 0)),
        "finding_player": int(getattr(h, "finding_player", 0)),
        "location": int(getattr(h, "location", 0)),
        "item": int(getattr(h, "item", 0)),
        "entrance": str(getattr(h, "entrance", "")),
        "item_flags": int(getattr(h, "item_flags", 0)),
        "status": status_val,
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--save-dir", required=True)
    args = parser.parse_args()

    files = glob.glob(f"{args.save_dir}/*.apsave")
    if not files:
        print(json.dumps({"error": "no .apsave file found"}))
        sys.exit(1)

    latest = max(files, key=os.path.getmtime)
    with open(latest, "rb") as fh:
        raw = fh.read()

    data: dict = pickle.loads(zlib.decompress(raw))  # noqa: S301

    location_checks = _save_slot_map(data.get("location_checks", {}))
    client_game_state = _save_slot_map(data.get("client_game_state", {}))
    received_items = _save_slot_map(data.get("received_items", {}))
    hints_map = _save_slot_map(data.get("hints", {}))
    hints_used_map = _save_slot_map(data.get("hints_used", {}))

    opts = data.get("game_options", {})
    location_check_points = int(opts.get("location_check_points", 1))

    all_slots = set(location_checks) | set(client_game_state) | set(received_items)
    result: dict[str, object] = {}
    for slot in all_slots:
        raw_items = received_items.get(slot, [])
        items = [
            [
                int(ni.item if hasattr(ni, "item") else ni[0]),
                int(ni.player if hasattr(ni, "player") else (ni[2] if len(ni) > 2 else 0)),
                int(ni.location if hasattr(ni, "location") else (ni[1] if len(ni) > 1 else 0)),
            ]
            for ni in raw_items
        ]
        hints: list[dict] = []
        for h in hints_map.get(slot, set()):
            try:
                hints.append(_extract_hint(h))
            except Exception:
                pass
        result[str(slot)] = {
            "checked_locations": list(location_checks.get(slot, set())),
            "received_items": items,
            "client_status": int(client_game_state.get(slot, 0)),
            "hints": hints,
            "hints_used": int(hints_used_map.get(slot, 0)),
            "location_check_points": location_check_points,
        }

    print(json.dumps(result))


if __name__ == "__main__":
    main()
