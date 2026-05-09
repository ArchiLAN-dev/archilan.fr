"""Tests for StateManager - RoomInfo, StatusUpdate, LocationChecks, ReceivedItems, GOAL (AC #4, #6, #7, #13)."""
from __future__ import annotations

import pytest

from bridge.bridge import PlayerState, StateManager


# --- RoomInfo ---

def test_room_info_sets_checks_total() -> None:
    state = StateManager()
    state.handle_room_info({"cmd": "RoomInfo", "locations": [47, 30, 22]})
    assert state.ensure_slot(0).checks_total == 47
    assert state.ensure_slot(1).checks_total == 30
    assert state.ensure_slot(2).checks_total == 22


def test_room_info_empty_locations_is_noop() -> None:
    state = StateManager()
    state.handle_room_info({"cmd": "RoomInfo", "locations": []})
    assert state.get_all() == {}


def test_room_info_sets_slot_names_from_slot_info() -> None:
    state = StateManager()
    state.handle_room_info({
        "cmd": "RoomInfo",
        "locations": [10, 20],
        "slot_info": {
            "0": {"name": "Alice_HK1", "game": "Hollow Knight"},
            "1": {"name": "Bob_LttP", "game": "A Link to the Past"},
        },
    })
    assert state.ensure_slot(0).slot_name == "Alice_HK1"
    assert state.ensure_slot(1).slot_name == "Bob_LttP"


# --- StatusUpdate ---

def test_status_update_sets_client_status() -> None:
    state = StateManager()
    state.handle_status_update({"cmd": "StatusUpdate", "status": 20, "slot": 1})
    assert state.ensure_slot(1).client_status == 20


def test_status_update_goal_records_timestamp() -> None:
    state = StateManager()
    state.handle_status_update({"cmd": "StatusUpdate", "status": 30, "slot": 2})
    ps = state.ensure_slot(2)
    assert ps.client_status == 30
    assert ps.goal_reached_at is not None
    assert "T" in ps.goal_reached_at  # ISO8601 timestamp


def test_status_update_goal_not_overwritten_on_second_call() -> None:
    state = StateManager()
    state.handle_status_update({"cmd": "StatusUpdate", "status": 30, "slot": 1})
    first_ts = state.ensure_slot(1).goal_reached_at
    state.handle_status_update({"cmd": "StatusUpdate", "status": 30, "slot": 1})
    assert state.ensure_slot(1).goal_reached_at == first_ts


def test_non_goal_status_does_not_set_goal_reached_at() -> None:
    state = StateManager()
    state.handle_status_update({"cmd": "StatusUpdate", "status": 20, "slot": 1})
    assert state.ensure_slot(1).goal_reached_at is None


# --- LocationChecks ---

def test_location_checks_update_checks_done() -> None:
    state = StateManager()
    state.set_checks_total(1, 47)
    state.handle_location_checks({"cmd": "LocationChecks", "slot": 1, "locations": [100, 101, 102]})
    assert state.ensure_slot(1).checks_done == 3


def test_location_checks_are_deduplicated() -> None:
    state = StateManager()
    state.handle_location_checks({"cmd": "LocationChecks", "slot": 1, "locations": [100, 101]})
    state.handle_location_checks({"cmd": "LocationChecks", "slot": 1, "locations": [101, 102]})
    assert state.ensure_slot(1).checks_done == 3  # {100, 101, 102}


# --- ReceivedItems ---

def test_received_items_increments_count() -> None:
    state = StateManager()
    state.handle_received_items({"cmd": "ReceivedItems", "slot": 1, "items": ["item_a", "item_b"]})
    assert state.ensure_slot(1).items_received == 2


def test_received_items_accumulates() -> None:
    state = StateManager()
    state.handle_received_items({"cmd": "ReceivedItems", "slot": 1, "items": ["x"]})
    state.handle_received_items({"cmd": "ReceivedItems", "slot": 1, "items": ["y", "z"]})
    assert state.ensure_slot(1).items_received == 3


def test_received_items_empty_list_is_noop() -> None:
    state = StateManager()
    state.handle_received_items({"cmd": "ReceivedItems", "slot": 1, "items": []})
    assert state.ensure_slot(1).items_received == 0


# --- State aggregation ---

def test_to_api_dict_format() -> None:
    state = StateManager()
    state.set_slot_name(1, "Alice_HK1")
    state.set_checks_total(1, 47)
    state.add_location_checks(1, list(range(12)))
    state.add_received_items(1, 8)
    state.update_client_status(1, 20)

    d = state.to_api_dict()
    assert "slots" in d
    slot = d["slots"]["1"]
    assert slot["slot_name"] == "Alice_HK1"
    assert slot["checks_done"] == 12
    assert slot["checks_total"] == 47
    assert slot["items_received"] == 8
    assert slot["client_status"] == 20
    assert slot["goal_reached_at"] is None


def test_to_api_dict_goal_slot() -> None:
    state = StateManager()
    state.set_checks_total(2, 47)
    state.add_location_checks(2, list(range(47)))
    state.update_client_status(2, 30)

    d = state.to_api_dict()
    slot = d["slots"]["2"]
    assert slot["client_status"] == 30
    assert slot["goal_reached_at"] is not None


def test_initial_state_from_save(tmp_path) -> None:
    """State loaded from save file is correctly accessible via to_api_dict."""
    ps1 = PlayerState(slot_index=1)
    ps1.checks_done = 10
    ps1.checks_total = 47
    ps1.client_status = 20

    state = StateManager({1: ps1})
    d = state.to_api_dict()
    assert d["slots"]["1"]["checks_done"] == 10
    assert d["slots"]["1"]["checks_total"] == 47
