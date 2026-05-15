"""Tests for StateManager - RoomInfo, StatusUpdate, LocationChecks, ReceivedItems, GOAL (AC #4, #6, #7)."""
from __future__ import annotations

from bridge.bridge import PlayerState, StateManager


# ---------------------------------------------------------------------------
# RoomInfo
# ---------------------------------------------------------------------------

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


def test_room_info_without_slot_info_does_not_crash() -> None:
    state = StateManager()
    state.handle_room_info({"cmd": "RoomInfo", "locations": [10]})
    assert state.ensure_slot(0).checks_total == 10


# ---------------------------------------------------------------------------
# StatusUpdate
# ---------------------------------------------------------------------------

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
    assert "T" in ps.goal_reached_at  # ISO 8601


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


# ---------------------------------------------------------------------------
# LocationChecks
# ---------------------------------------------------------------------------

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


def test_location_checks_empty_list_is_noop() -> None:
    state = StateManager()
    state.handle_location_checks({"cmd": "LocationChecks", "slot": 1, "locations": []})
    assert state.ensure_slot(1).checks_done == 0


# ---------------------------------------------------------------------------
# ReceivedItems (packet handler + counter methods)
# ---------------------------------------------------------------------------

def test_handle_received_items_increments_count() -> None:
    state = StateManager()
    state.handle_received_items({"cmd": "ReceivedItems", "slot": 1, "items": ["a", "b"]})
    assert state.ensure_slot(1).items_received == 2


def test_handle_received_items_accumulates() -> None:
    state = StateManager()
    state.handle_received_items({"cmd": "ReceivedItems", "slot": 1, "items": ["x"]})
    state.handle_received_items({"cmd": "ReceivedItems", "slot": 1, "items": ["y", "z"]})
    assert state.ensure_slot(1).items_received == 3


def test_handle_received_items_empty_is_noop() -> None:
    state = StateManager()
    state.handle_received_items({"cmd": "ReceivedItems", "slot": 1, "items": []})
    assert state.ensure_slot(1).items_received == 0


def test_add_received_items_increments_by_count() -> None:
    state = StateManager()
    state.add_received_items(1, 5)
    assert state.ensure_slot(1).items_received == 5
    state.add_received_items(1, 3)
    assert state.ensure_slot(1).items_received == 8


def test_add_item_received_tracks_full_tuple() -> None:
    state = StateManager()
    state.add_item_received(1, item_id=100, sender_slot=2, location_id=500)
    state.add_item_received(1, item_id=101, sender_slot=2, location_id=501)
    ps = state.ensure_slot(1)
    assert ps.items_received == 2
    assert (100, 2, 500) in ps._received_items
    assert (101, 2, 501) in ps._received_items


# ---------------------------------------------------------------------------
# State aggregation / to_api_dict
# ---------------------------------------------------------------------------

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


def test_initial_state_from_save() -> None:
    ps1 = PlayerState(slot_index=1)
    ps1.checks_done = 10
    ps1.checks_total = 47
    ps1.client_status = 20

    state = StateManager({1: ps1})
    d = state.to_api_dict()
    assert d["slots"]["1"]["checks_done"] == 10
    assert d["slots"]["1"]["checks_total"] == 47


def test_ensure_slot_creates_if_missing() -> None:
    state = StateManager()
    ps = state.ensure_slot(5)
    assert ps.slot_index == 5
    assert state.ensure_slot(5) is ps  # same object


def test_get_all_returns_copy() -> None:
    state = StateManager()
    state.ensure_slot(1)
    copy = state.get_all()
    copy[99] = PlayerState(slot_index=99)
    assert 99 not in state._states
