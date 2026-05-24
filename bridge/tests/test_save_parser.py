"""Tests for .apsave parsing (AC #8, #13)."""
from __future__ import annotations

import json
import os
import pickle
import zlib
from pathlib import Path

import pytest

from bridge.bridge import load_save_state
from bridge.core.save_parser import load_save_state_from_json


def _write_apsave(tmp_path: Path, data: dict) -> str:
    compressed = zlib.compress(pickle.dumps(data))
    p = tmp_path / "session.apsave"
    p.write_bytes(compressed)
    return str(tmp_path)


def test_empty_save_dir_returns_empty(tmp_path: Path) -> None:
    result = load_save_state(str(tmp_path))
    assert result == {}


def test_missing_save_dir_returns_empty() -> None:
    result = load_save_state("/does/not/exist")
    assert result == {}


def test_corrupt_file_returns_empty(tmp_path: Path) -> None:
    (tmp_path / "bad.apsave").write_bytes(b"not valid")
    result = load_save_state(str(tmp_path))
    assert result == {}


def test_basic_save_state_parsed(tmp_path: Path) -> None:
    data = {
        "location_checks": {1: {100, 101, 102}, 2: {200}},
        "client_game_state": {1: 20, 2: 30},
    }
    save_dir = _write_apsave(tmp_path, data)
    result = load_save_state(save_dir)

    assert 1 in result
    assert result[1].checks_done == 3
    assert result[1].client_status == 20

    assert 2 in result
    assert result[2].checks_done == 1
    assert result[2].client_status == 30


def test_goal_status_preserved(tmp_path: Path) -> None:
    data = {
        "location_checks": {1: set(range(47))},
        "client_game_state": {1: 30},  # GOAL
    }
    save_dir = _write_apsave(tmp_path, data)
    result = load_save_state(save_dir)

    assert result[1].checks_done == 47
    assert result[1].client_status == 30


def test_slot_with_no_location_checks(tmp_path: Path) -> None:
    data = {
        "location_checks": {},
        "client_game_state": {1: 5},
    }
    save_dir = _write_apsave(tmp_path, data)
    result = load_save_state(save_dir)

    assert result[1].checks_done == 0
    assert result[1].client_status == 5


def test_slot_with_no_game_state(tmp_path: Path) -> None:
    data = {
        "location_checks": {3: {1, 2, 3}},
        "client_game_state": {},
    }
    save_dir = _write_apsave(tmp_path, data)
    result = load_save_state(save_dir)

    assert result[3].checks_done == 3
    assert result[3].client_status == 0  # default UNKNOWN


@pytest.mark.parametrize("slots,expected_counts", [
    ({1: set(range(10)), 2: set(range(5))}, {1: 10, 2: 5}),
    ({0: set(), 1: set(range(47))}, {0: 0, 1: 47}),
])
def test_multiple_slots(tmp_path: Path, slots: dict, expected_counts: dict) -> None:
    data = {"location_checks": slots, "client_game_state": {}}
    save_dir = _write_apsave(tmp_path, data)
    result = load_save_state(save_dir)

    for slot, expected in expected_counts.items():
        assert result[slot].checks_done == expected


# ---------------------------------------------------------------------------
# load_save_state_from_json (Docker path)
# ---------------------------------------------------------------------------

def test_load_from_json_basic() -> None:
    payload = {
        "1": {
            "checked_locations": [100, 101, 102],
            "received_items": [[500, 2, 100], [501, 2, 101]],
            "client_status": 20,
            "hints": [],
            "hints_used": 0,
            "location_check_points": 1,
        }
    }
    result = load_save_state_from_json(json.dumps(payload))
    assert 1 in result
    ps = result[1]
    assert ps.checks_done == 3
    assert ps._checked_locations == {100, 101, 102}
    assert ps.items_received == 2
    assert ps.client_status == 20


def test_load_from_json_goal_status() -> None:
    payload = {
        "2": {
            "checked_locations": list(range(47)),
            "received_items": [],
            "client_status": 30,
            "hints": [],
            "hints_used": 0,
            "location_check_points": 1,
        }
    }
    result = load_save_state_from_json(json.dumps(payload))
    assert result[2].client_status == 30
    assert result[2].goal_reached_at is not None


def test_load_from_json_with_hints() -> None:
    payload = {
        "1": {
            "checked_locations": [],
            "received_items": [],
            "client_status": 0,
            "hints": [
                {"receiving_player": 1, "finding_player": 2, "location": 81000,
                 "item": 123456, "entrance": "", "item_flags": 1, "status": 0}
            ],
            "hints_used": 1,
            "location_check_points": 2,
        }
    }
    result = load_save_state_from_json(json.dumps(payload))
    ps = result[1]
    assert len(ps._hints) == 1
    assert ps._hints[0].location_id == 81000
    assert ps._hints[0].item_id == 123456
    assert ps.hints_used == 1
    assert ps.hint_points_per_check == 2


def test_load_from_json_error_key_returns_empty() -> None:
    result = load_save_state_from_json(json.dumps({"error": "no .apsave file found"}))
    assert result == {}


def test_load_from_json_invalid_json_returns_empty() -> None:
    result = load_save_state_from_json("not json at all")
    assert result == {}


def test_latest_file_selected_by_mtime(tmp_path: Path) -> None:
    # Write an old file with wrong data
    old_data = {"location_checks": {1: {999}}, "client_game_state": {1: 5}}
    old = tmp_path / "old.apsave"
    old.write_bytes(zlib.compress(pickle.dumps(old_data)))
    # Make old file older
    old_mtime = old.stat().st_mtime - 100
    os.utime(old, (old_mtime, old_mtime))

    # Write a new file with the correct data
    new_data = {"location_checks": {1: {1, 2}}, "client_game_state": {1: 20}}
    new = tmp_path / "new.apsave"
    new.write_bytes(zlib.compress(pickle.dumps(new_data)))

    result = load_save_state(str(tmp_path))
    assert result[1].checks_done == 2
    assert result[1].client_status == 20
