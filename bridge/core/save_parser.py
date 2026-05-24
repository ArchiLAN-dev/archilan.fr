from __future__ import annotations

import glob
import json
import logging
import os
import pickle
import sys
import zlib
from datetime import datetime, timezone
from typing import Any

from .domain import HintInfo, PlayerState


def _save_slot_map(mapping: dict[Any, Any]) -> dict[int, Any]:
    """Normalize AP save keys: (team, slot[, ...]) tuples → slot int (team 0 only).

    received_items uses 3-element keys (team, slot, remote_flag) - values for the
    same slot are merged so the total count is correct.
    """
    result: dict[int, Any] = {}
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


def _extract_hints(raw_hints: set) -> list[HintInfo]:
    """Convert a set of AP Hint namedtuples to a list of HintInfo."""
    result = []
    for h in raw_hints:
        try:
            status_raw = getattr(h, "status", 0)
            # HintStatus may be an enum (AP source loaded) or a plain int
            status_val = status_raw.value if hasattr(status_raw, "value") else int(status_raw)
            # Older AP versions have no status field but have found=True/False → map to 40
            if status_val == 0 and getattr(h, "found", False):
                status_val = 40
            result.append(HintInfo(
                receiving_player=int(getattr(h, "receiving_player", 0)),
                finding_player=int(getattr(h, "finding_player", 0)),
                location_id=int(getattr(h, "location", 0)),
                item_id=int(getattr(h, "item", 0)),
                entrance=str(getattr(h, "entrance", "")),
                item_flags=int(getattr(h, "item_flags", 0)),
                status=status_val,
            ))
        except Exception:
            pass
    return result


def load_save_state_from_json(json_str: str) -> dict[int, PlayerState]:
    """Parse JSON output from read_save.py (Docker path) into PlayerState per slot."""
    log = logging.getLogger(__name__)
    try:
        data: dict[str, Any] = json.loads(json_str)
        if "error" in data:
            log.warning("read_save.py reported error: %s", data["error"])
            return {}

        states: dict[int, PlayerState] = {}
        for slot_str, info in data.items():
            try:
                slot = int(slot_str)
                ps = PlayerState(slot_index=slot)
                ps._checked_locations = {int(x) for x in info.get("checked_locations", [])}
                ps.checks_done = len(ps._checked_locations)
                ps.client_status = int(info.get("client_status", 0))
                if ps.client_status == 30:
                    ps.goal_reached_at = datetime.now(timezone.utc).isoformat()
                ps._received_items = [
                    (int(item[0]), int(item[1]), int(item[2]))
                    for item in info.get("received_items", [])
                ]
                ps.items_received = len(ps._received_items)
                ps.hints_used = int(info.get("hints_used", 0))
                ps.hint_points_per_check = int(info.get("location_check_points", 1))
                hints: list[HintInfo] = []
                for h in info.get("hints", []):
                    try:
                        hints.append(HintInfo(
                            receiving_player=int(h.get("receiving_player", 0)),
                            finding_player=int(h.get("finding_player", 0)),
                            location_id=int(h.get("location", 0)),
                            item_id=int(h.get("item", 0)),
                            entrance=str(h.get("entrance", "")),
                            item_flags=int(h.get("item_flags", 0)),
                            status=int(h.get("status", 0)),
                        ))
                    except Exception:
                        pass
                ps._hints = hints
                states[slot] = ps
            except Exception as exc:
                log.warning("load_save_state_from_json: slot %s failed: %s", slot_str, exc)

        log.info(
            "save state loaded from JSON: %d slot(s) - statuses: %s",
            len(states),
            {s: p.client_status for s, p in states.items()},
        )
        return states
    except Exception as exc:
        log.warning("load_save_state_from_json failed: %s", exc)
        return {}


def load_save_state(save_dir: str) -> dict[int, PlayerState]:
    """Read latest .apsave file and return initial PlayerState per slot index."""
    _ap_src = "/app/ArchipelagoSrc"
    if os.path.isdir(_ap_src) and _ap_src not in sys.path:
        sys.path.insert(0, _ap_src)

    log = logging.getLogger(__name__)
    try:
        files = glob.glob(f"{save_dir}/*.apsave")
        if not files:
            return {}
        latest = max(files, key=os.path.getmtime)
        with open(latest, "rb") as fh:
            raw = fh.read()
        data: dict[str, Any] = pickle.loads(zlib.decompress(raw))  # noqa: S301

        log.info("save keys: %s", list(data.keys()))
        raw_cgs = data.get("client_game_state", {})
        log.info("client_game_state raw (first 10): %s", dict(list(raw_cgs.items())[:10]))

        location_checks = _save_slot_map(data.get("location_checks", {}))
        client_game_state = _save_slot_map(raw_cgs)
        received_items = _save_slot_map(data.get("received_items", {}))
        hints_map = _save_slot_map(data.get("hints", {}))
        hints_used_map = _save_slot_map(data.get("hints_used", {}))

        # NOTE: game_options.hint_cost is a PERCENTAGE (e.g. 10), not a point cost.
        # The actual point cost per hint is provided by the AP server in the RoomInfo
        # WebSocket packet and applied by StateManager.handle_room_info().
        # We only read location_check_points here (it IS already in points).
        opts = data.get("game_options", {})
        location_check_points = int(opts.get("location_check_points", 1))

        log.info("client_game_state after slot_map: %s", client_game_state)

        all_slots = set(location_checks) | set(client_game_state) | set(received_items)
        states: dict[int, PlayerState] = {}
        for slot in all_slots:
            checked: set[int] = set(location_checks.get(slot, set()))
            ps = PlayerState(slot_index=slot)
            ps._checked_locations = checked
            ps.checks_done = len(checked)
            ps.client_status = int(client_game_state.get(slot, 0))
            if ps.client_status == 30:
                ps.goal_reached_at = datetime.now(timezone.utc).isoformat()
            raw_items = received_items.get(slot, [])
            ps._received_items = [
                (
                    int(ni.item if hasattr(ni, "item") else ni[0]),
                    int(ni.player if hasattr(ni, "player") else (ni[2] if len(ni) > 2 else 0)),
                    int(ni.location if hasattr(ni, "location") else (ni[1] if len(ni) > 1 else 0)),
                )
                for ni in raw_items
            ]
            ps.items_received = len(ps._received_items)
            ps.hints_used = int(hints_used_map.get(slot, 0))
            ps.hint_points_per_check = location_check_points
            # ps.hint_cost intentionally left at default 0 - updated by handle_room_info()
            ps._hints = _extract_hints(hints_map.get(slot, set()))
            states[slot] = ps

        log.info(
            "save state loaded: %d slot(s) - statuses: %s",
            len(states),
            {s: p.client_status for s, p in states.items()},
        )
        return states
    except Exception as exc:
        log.warning("failed to load save state: %s", exc)
        return {}
