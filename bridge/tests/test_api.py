"""Tests for REST API endpoints (AC #11)."""
from __future__ import annotations

import json
from unittest.mock import AsyncMock, MagicMock

import pytest
from aiohttp.test_utils import TestClient, TestServer

from bridge.bridge import (
    ArchipelagoClient,
    Config,
    HintInfo,
    MercurePublisher,
    StateManager,
    TokenManager,
    create_app,
)
from bridge.core.reachable import _reachable_cache


def _make_config() -> Config:
    return Config(
        mercure_hub_url="http://hub.test",
        central_api_secret="s",
        symfony_internal_url="http://api.test",
        run_id="run-1",
    )


def _make_app() -> tuple[object, StateManager, ArchipelagoClient]:
    config = _make_config()
    state = StateManager()
    token_mgr = MagicMock(spec=TokenManager)
    token_mgr.token = "fake-token"
    publisher = MagicMock(spec=MercurePublisher)
    publisher.publish = AsyncMock()
    # recompute_event and reachable_semaphore are optional
    ap_client = ArchipelagoClient(config, state, publisher)
    app = create_app(state, ap_client)
    return app, state, ap_client


# ---------------------------------------------------------------------------
# GET /health
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_health_ok() -> None:
    app, state, ap_client = _make_app()
    async with TestClient(TestServer(app)) as client:
        resp = await client.get("/health")
        assert resp.status == 200
        data = await resp.json()
        assert data["status"] == "ok"
        assert data["ws_connected"] is False


@pytest.mark.asyncio
async def test_health_reflects_ws_connected() -> None:
    app, state, ap_client = _make_app()
    ap_client.ws_connected = True
    async with TestClient(TestServer(app)) as client:
        resp = await client.get("/health")
        data = await resp.json()
        assert data["ws_connected"] is True


# ---------------------------------------------------------------------------
# GET /state
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_get_state_empty() -> None:
    app, state, ap_client = _make_app()
    async with TestClient(TestServer(app)) as client:
        resp = await client.get("/state")
        assert resp.status == 200
        data = await resp.json()
        assert data == {"slots": {}}


@pytest.mark.asyncio
async def test_get_state_with_players() -> None:
    app, state, ap_client = _make_app()
    state.set_slot_name(1, "Alice_HK1")
    state.set_checks_total(1, 47)
    state.add_location_checks(1, list(range(12)))
    state.add_received_items(1, 5)
    state.update_client_status(1, 20)

    async with TestClient(TestServer(app)) as client:
        resp = await client.get("/state")
        data = await resp.json()
        slot = data["slots"]["1"]
        assert slot["slot_name"] == "Alice_HK1"
        assert slot["checks_done"] == 12
        assert slot["checks_total"] == 47
        assert slot["items_received"] == 5
        assert slot["client_status"] == 20


# ---------------------------------------------------------------------------
# POST /commands
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_post_command_valid() -> None:
    app, state, ap_client = _make_app()
    ap_client.ws_connected = True
    ap_client._ws = AsyncMock()
    ap_client._ws.send = AsyncMock()

    async with TestClient(TestServer(app)) as client:
        resp = await client.post("/commands", json={"command": "/hint Alice"})
        assert resp.status == 200
        data = await resp.json()
        assert data["ok"] is True


@pytest.mark.asyncio
async def test_post_command_missing_field() -> None:
    app, state, ap_client = _make_app()
    async with TestClient(TestServer(app)) as client:
        resp = await client.post("/commands", json={"not_command": "x"})
        assert resp.status == 400


@pytest.mark.asyncio
async def test_post_command_empty_string() -> None:
    app, state, ap_client = _make_app()
    async with TestClient(TestServer(app)) as client:
        resp = await client.post("/commands", json={"command": "   "})
        assert resp.status == 400


@pytest.mark.asyncio
async def test_post_command_invalid_json() -> None:
    app, state, ap_client = _make_app()
    async with TestClient(TestServer(app)) as client:
        resp = await client.post(
            "/commands",
            data="not-json",
            headers={"Content-Type": "application/json"},
        )
        assert resp.status == 400


@pytest.mark.asyncio
async def test_post_command_ws_disconnected() -> None:
    app, state, ap_client = _make_app()
    ap_client.ws_connected = False

    async with TestClient(TestServer(app)) as client:
        resp = await client.post("/commands", json={"command": "/release Alice"})
        assert resp.status == 503


@pytest.mark.asyncio
async def test_post_command_forwards_to_ws() -> None:
    app, state, ap_client = _make_app()
    ap_client.ws_connected = True
    ap_client._ws = AsyncMock()
    ap_client._ws.send = AsyncMock()

    async with TestClient(TestServer(app)) as client:
        await client.post("/commands", json={"command": "/release Alice"})

    call_args = ap_client._ws.send.call_args[0][0]
    packets = json.loads(call_args)
    assert len(packets) == 1
    assert packets[0]["cmd"] == "Say"
    assert packets[0]["text"] == "/release Alice"


# ---------------------------------------------------------------------------
# GET /hints/{slot}
# ---------------------------------------------------------------------------

def _populate_store(ap_client: ArchipelagoClient, game: str = "Hollow Knight") -> None:
    """Load game data + player info into ap_client's DataPackageStore."""
    ap_client._store.handle_data_package({
        "data": {
            "games": {
                game: {
                    "item_name_to_id": {"Grub": 8149},
                    "location_name_to_id": {"Fungal Wastes - Cornifer": 8003},
                }
            }
        }
    })
    ap_client._store.handle_connected({
        "players": [{"slot": 1, "alias": "Alice", "name": "Alice_HK"}],
        "slot_info": {"1": {"game": game}},
    })


@pytest.mark.asyncio
async def test_get_hints_returns_400_for_invalid_slot() -> None:
    app, state, ap_client = _make_app()
    async with TestClient(TestServer(app)) as client:
        resp = await client.get("/hints/not-a-number")
        assert resp.status == 400


@pytest.mark.asyncio
async def test_get_hints_empty_when_no_hints() -> None:
    app, state, ap_client = _make_app()
    state.ensure_slot(1)
    async with TestClient(TestServer(app)) as client:
        resp = await client.get("/hints/1")
        assert resp.status == 200
        data = await resp.json()
        assert data["slot"] == 1
        assert data["hints"] == []
        assert data["hints_used"] == 0


@pytest.mark.asyncio
async def test_get_hints_resolves_names_after_merge_from_save() -> None:
    """Names stored as IDs in apsave hints must be resolved by get_hints via resolve_slot_hint_names."""
    app, state, ap_client = _make_app()
    _populate_store(ap_client)

    # Simulate a hint loaded from apsave: names are still raw ID fallbacks
    unresolved_hint = HintInfo(
        receiving_player=1,
        finding_player=1,
        location_id=8003,
        item_id=8149,
        entrance="",
        item_flags=0,
        status=0,
        item_name="Item #8149",        # unresolved - as if loaded from apsave
        location_name="Location #8003",  # unresolved
    )
    state.add_hint(1, unresolved_hint)

    async with TestClient(TestServer(app)) as client:
        resp = await client.get("/hints/1")
        assert resp.status == 200
        data = await resp.json()

    assert len(data["hints"]) == 1
    hint = data["hints"][0]
    assert hint["item_name"] == "Grub", "item name should be resolved from DataPackageStore"
    assert hint["location_name"] == "Fungal Wastes - Cornifer", "location name should be resolved"
    assert hint["receiving_player_name"] == "Alice"


@pytest.mark.asyncio
async def test_get_hints_includes_hint_cost_and_budget() -> None:
    app, state, ap_client = _make_app()
    ps = state.ensure_slot(1)
    ps.hint_cost = 91
    ps.hints_used = 3
    ps.checks_done = 50
    ps.hint_points_per_check = 1

    async with TestClient(TestServer(app)) as client:
        resp = await client.get("/hints/1")
        data = await resp.json()

    assert data["hint_cost"] == 91
    assert data["hints_used"] == 3
    # hint_points_available = checks_done * per_check - hints_used * cost = 50*1 - 3*91 = -223 → clamped to 0
    assert data["hint_points_available"] == 0


@pytest.mark.asyncio
async def test_add_hint_does_not_modify_hints_used() -> None:
    """hints_used must only be updated by apsave loading or REST paid-hint handler, never by add_hint."""
    app, state, ap_client = _make_app()
    ps = state.ensure_slot(1)
    ps.hints_used = 5  # set authoritative value

    hint = HintInfo(
        receiving_player=1, finding_player=1, location_id=8003, item_id=8149,
        entrance="", item_flags=0, status=0,
    )
    state.add_hint(1, hint)
    state.add_hint(1, HintInfo(
        receiving_player=1, finding_player=1, location_id=8004, item_id=8150,
        entrance="", item_flags=0, status=0,
    ))

    assert ps.hints_used == 5, "add_hint must not touch hints_used"


# ---------------------------------------------------------------------------
# GET /item-locations/{slot}
# ---------------------------------------------------------------------------

def _seed_reachable_cache(sender_slot: int, checks: list[dict]) -> None:
    """Inject fake reachability data into the module-level cache used by rest.py."""
    _reachable_cache[sender_slot] = (
        (0, 0),  # cache_key (checks_done, items_received)
        {
            "reachable_unchecked": [],
            "reachable_checked": [],
            "unreachable_unchecked": [],
            "checked_unreachable": [],
            **checks,  # caller merges into the right list(s)
        },
    )


def _make_check(loc_id: int, loc_name: str, item_id: int, item_name: str, receiving_slot: int) -> dict:
    return {
        "id": loc_id,
        "name": loc_name,
        "item": {"id": item_id, "name": item_name, "flags": 0, "slot": receiving_slot, "slot_name": f"Player {receiving_slot}"},
    }


@pytest.mark.asyncio
async def test_get_item_locations_invalid_slot() -> None:
    app, state, ap_client = _make_app()
    async with TestClient(TestServer(app)) as client:
        resp = await client.get("/item-locations/not-a-number")
        assert resp.status == 400


@pytest.mark.asyncio
async def test_get_item_locations_empty_when_cache_is_empty() -> None:
    _reachable_cache.clear()

    app, state, ap_client = _make_app()
    async with TestClient(TestServer(app)) as client:
        resp = await client.get("/item-locations/1")
        assert resp.status == 200
        data = await resp.json()
        assert data["slot"] == 1
        assert data["locations"] == []


@pytest.mark.asyncio
async def test_get_item_locations_same_game_item() -> None:
    """Slot 1 has an unchecked location whose item goes to slot 1 (same game)."""
    _reachable_cache.clear()

    app, state, ap_client = _make_app()
    ap_client._store.handle_connected({
        "players": [{"slot": 1, "alias": "Alice", "name": "Alice_HK"}],
        "slot_info": {"1": {"game": "Hollow Knight"}},
    })
    _seed_reachable_cache(1, {"reachable_unchecked": [
        _make_check(8003, "Fungal Wastes - Cornifer", 8149, "Grub", 1),
    ]})

    async with TestClient(TestServer(app)) as client:
        resp = await client.get("/item-locations/1")
        data = await resp.json()

    assert len(data["locations"]) == 1
    loc = data["locations"][0]
    assert loc["item_id"] == 8149
    assert loc["item_name"] == "Grub"
    assert loc["location_id"] == 8003
    assert loc["location_name"] == "Fungal Wastes - Cornifer"
    assert loc["finding_player"] == 1
    assert loc["finding_player_name"] == "Alice"
    assert loc["check_status"] == "reachable"


@pytest.mark.asyncio
async def test_get_item_locations_cross_game_item() -> None:
    """Slot 2 (Bob/ALTTP) has a location whose item goes to slot 1 (Alice/HK)."""
    _reachable_cache.clear()

    app, state, ap_client = _make_app()
    ap_client._store.handle_connected({
        "players": [
            {"slot": 1, "alias": "Alice", "name": "Alice_HK"},
            {"slot": 2, "alias": "Bob", "name": "Bob_ALTTP"},
        ],
        "slot_info": {"1": {"game": "Hollow Knight"}, "2": {"game": "A Link to the Past"}},
    })
    _seed_reachable_cache(2, {"unreachable_unchecked": [
        _make_check(10, "Link's House", 8149, "Grub", 1),
    ]})

    async with TestClient(TestServer(app)) as client:
        resp = await client.get("/item-locations/1")
        data = await resp.json()

    assert len(data["locations"]) == 1
    loc = data["locations"][0]
    assert loc["location_name"] == "Link's House"
    assert loc["finding_player"] == 2
    assert loc["finding_player_name"] == "Bob"
    assert loc["check_status"] == "blocked"


@pytest.mark.asyncio
async def test_get_item_locations_check_status_mapping() -> None:
    """Each source list maps to the correct check_status value."""
    _reachable_cache.clear()

    app, state, ap_client = _make_app()
    _seed_reachable_cache(1, {
        "reachable_unchecked":   [_make_check(1, "Loc A", 100, "Item A", 1)],
        "reachable_checked":     [_make_check(2, "Loc B", 101, "Item B", 1)],
        "unreachable_unchecked": [_make_check(3, "Loc C", 102, "Item C", 1)],
        "checked_unreachable":   [_make_check(4, "Loc D", 103, "Item D", 1)],
    })

    async with TestClient(TestServer(app)) as client:
        resp = await client.get("/item-locations/1")
        data = await resp.json()

    by_loc = {loc["location_id"]: loc["check_status"] for loc in data["locations"]}
    assert by_loc[1] == "reachable"
    assert by_loc[2] == "checked"
    assert by_loc[3] == "blocked"
    assert by_loc[4] == "checked"


@pytest.mark.asyncio
async def test_get_item_locations_filters_out_other_slots_items() -> None:
    """Only locations whose item.slot matches the requested slot are returned."""
    _reachable_cache.clear()

    app, state, ap_client = _make_app()
    _seed_reachable_cache(1, {"reachable_unchecked": [
        _make_check(8003, "Loc for Alice", 8149, "Grub", 1),   # for slot 1 ✓
        _make_check(8004, "Loc for Bob",   8200, "Shade Soul", 2),  # for slot 2 ✗
    ]})

    async with TestClient(TestServer(app)) as client:
        resp = await client.get("/item-locations/1")
        data = await resp.json()

    assert len(data["locations"]) == 1
    assert data["locations"][0]["location_name"] == "Loc for Alice"


@pytest.mark.asyncio
async def test_get_item_locations_multiple_copies_across_games() -> None:
    """Same item at two locations in two different games."""
    _reachable_cache.clear()

    app, state, ap_client = _make_app()
    ap_client._store.handle_connected({
        "players": [
            {"slot": 1, "alias": "Alice", "name": "Alice_HK"},
            {"slot": 2, "alias": "Bob", "name": "Bob_ALTTP"},
        ],
        "slot_info": {"1": {"game": "HK"}, "2": {"game": "ALTTP"}},
    })
    _seed_reachable_cache(1, {"reachable_unchecked": [_make_check(10, "HK Loc", 99, "Sword", 1)]})
    _seed_reachable_cache(2, {"unreachable_unchecked": [_make_check(20, "ALTTP Loc", 99, "Sword", 1)]})

    async with TestClient(TestServer(app)) as client:
        resp = await client.get("/item-locations/1")
        data = await resp.json()

    assert len(data["locations"]) == 2
    finding_players = {loc["finding_player"] for loc in data["locations"]}
    assert finding_players == {1, 2}
