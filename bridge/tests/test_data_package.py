"""Tests for DataPackageStore - ID resolution and alias lookup."""
from __future__ import annotations

from bridge.bridge import DataPackageStore


def _store_with_game(game: str = "Hollow Knight") -> DataPackageStore:
    store = DataPackageStore()
    store.handle_data_package({
        "data": {
            "games": {
                game: {
                    "item_name_to_id": {
                        "Vengeful Spirit": 100,
                        "Desolate Dive": 101,
                    },
                    "location_name_to_id": {
                        "Grubfather": 200,
                        "Seer": 201,
                    },
                }
            }
        }
    })
    store.handle_connected({
        "players": [
            {"slot": 1, "alias": "Alice", "name": "Alice_HK"},
            {"slot": 2, "alias": "Bob",   "name": "Bob_LttP"},
        ],
        "slot_info": {
            "1": {"game": game},
            "2": {"game": "A Link to the Past"},
        },
    })
    return store


# ---------------------------------------------------------------------------
# resolve_player
# ---------------------------------------------------------------------------

def test_resolve_player_known() -> None:
    store = _store_with_game()
    assert store.resolve_player(1) == "Alice"
    assert store.resolve_player(2) == "Bob"


def test_resolve_player_unknown_falls_back() -> None:
    store = DataPackageStore()
    assert store.resolve_player(99) == "Player 99"


def test_resolve_player_prefers_alias_over_name() -> None:
    store = DataPackageStore()
    store.handle_connected({
        "players": [{"slot": 1, "alias": "TheAlias", "name": "TheName"}],
        "slot_info": {},
    })
    assert store.resolve_player(1) == "TheAlias"


# ---------------------------------------------------------------------------
# resolve_item
# ---------------------------------------------------------------------------

def test_resolve_item_known() -> None:
    store = _store_with_game()
    assert store.resolve_item(100, player_slot=1) == "Vengeful Spirit"
    assert store.resolve_item(101, player_slot=1) == "Desolate Dive"


def test_resolve_item_unknown_id_falls_back() -> None:
    store = _store_with_game()
    assert store.resolve_item(9999, player_slot=1) == "Item #9999"


def test_resolve_item_wrong_game_falls_back() -> None:
    store = _store_with_game()
    # Slot 2 plays "A Link to the Past", which has no data package → fallback
    assert store.resolve_item(100, player_slot=2) == "Item #100"


# ---------------------------------------------------------------------------
# resolve_location
# ---------------------------------------------------------------------------

def test_resolve_location_known() -> None:
    store = _store_with_game()
    assert store.resolve_location(200, player_slot=1) == "Grubfather"


def test_resolve_location_unknown_falls_back() -> None:
    store = _store_with_game()
    assert store.resolve_location(9999, player_slot=1) == "Location #9999"


# ---------------------------------------------------------------------------
# slot_by_alias
# ---------------------------------------------------------------------------

def test_slot_by_alias_found() -> None:
    store = _store_with_game()
    assert store.slot_by_alias("Alice") == 1
    assert store.slot_by_alias("Bob") == 2


def test_slot_by_alias_not_found_returns_zero() -> None:
    store = _store_with_game()
    assert store.slot_by_alias("Unknown") == 0


# ---------------------------------------------------------------------------
# handle_data_package - non-dict game data is skipped
# ---------------------------------------------------------------------------

def test_handle_data_package_skips_non_dict_games() -> None:
    store = DataPackageStore()
    store.handle_data_package({
        "data": {
            "games": {
                "ValidGame": {
                    "item_name_to_id": {"ItemA": 1},
                    "location_name_to_id": {},
                },
                "BadGame": "this is not a dict",
            }
        }
    })
    # Valid game registered, bad game silently ignored
    store.handle_connected({
        "players": [{"slot": 1, "alias": "P1"}],
        "slot_info": {"1": {"game": "ValidGame"}},
    })
    assert store.resolve_item(1, player_slot=1) == "ItemA"
