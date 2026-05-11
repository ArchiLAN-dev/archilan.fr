"""Tests for PlayerState domain object."""
from __future__ import annotations

from bridge.bridge import PlayerState


def test_defaults() -> None:
    ps = PlayerState(slot_index=3)
    assert ps.slot_index == 3
    assert ps.slot_name == ""
    assert ps.checks_done == 0
    assert ps.checks_total == 0
    assert ps.items_received == 0
    assert ps.client_status == 0
    assert ps.goal_reached_at is None
    assert ps.reachable_now is None
    assert ps._checked_locations == set()
    assert ps._received_items == []


def test_to_dict_keys() -> None:
    ps = PlayerState(slot_index=1)
    d = ps.to_dict()
    assert set(d.keys()) == {
        "slot_name", "checks_done", "checks_total",
        "items_received", "client_status", "goal_reached_at", "reachable_now",
        "hints_used", "hint_points_available",
    }
    # slot_index is internal, not exposed
    assert "slot_index" not in d


def test_to_dict_values() -> None:
    ps = PlayerState(slot_index=1)
    ps.slot_name = "Alice"
    ps.checks_done = 12
    ps.checks_total = 47
    ps.items_received = 8
    ps.client_status = 20
    ps.reachable_now = 3

    d = ps.to_dict()
    assert d["slot_name"] == "Alice"
    assert d["checks_done"] == 12
    assert d["checks_total"] == 47
    assert d["items_received"] == 8
    assert d["client_status"] == 20
    assert d["reachable_now"] == 3
    assert d["goal_reached_at"] is None


def test_to_dict_goal() -> None:
    ps = PlayerState(slot_index=2)
    ps.client_status = 30
    ps.goal_reached_at = "2024-01-01T12:00:00+00:00"
    d = ps.to_dict()
    assert d["client_status"] == 30
    assert d["goal_reached_at"] == "2024-01-01T12:00:00+00:00"


def test_independent_internal_state() -> None:
    ps1 = PlayerState(slot_index=1)
    ps2 = PlayerState(slot_index=2)
    ps1._checked_locations.add(100)
    ps1._received_items.append((1, 2, 3))
    assert 100 not in ps2._checked_locations
    assert ps2._received_items == []
