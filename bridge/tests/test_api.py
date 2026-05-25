"""Tests for REST API endpoints."""
from __future__ import annotations

import json
from unittest.mock import AsyncMock

import pytest
from httpx import ASGITransport, AsyncClient

from bridge.bridge import (
    ArchipelagoClient,
    Config,
    HintInfo,
    StateManager,
    create_app,
)
from bridge.core.reachable import _reachable_cache


def _make_config() -> Config:
    return Config(
        session_id="run-1",
        internal_token="test-token",
    )


def _make_app() -> tuple[object, StateManager, ArchipelagoClient]:
    config = _make_config()
    state = StateManager()
    broadcast = AsyncMock()
    ap_client = ArchipelagoClient(config, state, broadcast)
    app = create_app(state, ap_client)
    return app, state, ap_client


# ---------------------------------------------------------------------------
# GET /health
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_health_ok() -> None:
    app, state, ap_client = _make_app()
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/health")
        assert resp.status_code == 200
        data = resp.json()
        assert data["status"] == "ok"
        assert data["wsConnected"] is False


@pytest.mark.asyncio
async def test_health_reflects_ws_connected() -> None:
    app, state, ap_client = _make_app()
    ap_client.ws_connected = True
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/health")
        data = resp.json()
        assert data["wsConnected"] is True


# ---------------------------------------------------------------------------
# GET /state (legacy)
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_get_state_empty() -> None:
    app, state, ap_client = _make_app()
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/state")
        assert resp.status_code == 200
        data = resp.json()
        assert data == {"slots": {}}


@pytest.mark.asyncio
async def test_get_state_with_players() -> None:
    app, state, ap_client = _make_app()
    state.set_slot_name(1, "Alice_HK1")
    state.set_checks_total(1, 47)
    state.add_location_checks(1, list(range(12)))
    state.add_received_items(1, 5)
    state.update_client_status(1, 20)

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/state")
        data = resp.json()
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

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post("/commands", json={"command": "/hint Alice"})
        assert resp.status_code == 200
        data = resp.json()
        assert data["ok"] is True


@pytest.mark.asyncio
async def test_post_command_missing_field() -> None:
    app, state, ap_client = _make_app()
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post("/commands", json={"not_command": "x"})
        assert resp.status_code == 422


@pytest.mark.asyncio
async def test_post_command_empty_string() -> None:
    app, state, ap_client = _make_app()
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post("/commands", json={"command": "   "})
        assert resp.status_code == 422


@pytest.mark.asyncio
async def test_post_command_invalid_json() -> None:
    app, state, ap_client = _make_app()
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post(
            "/commands",
            content=b"not-json",
            headers={"Content-Type": "application/json"},
        )
        assert resp.status_code == 422


@pytest.mark.asyncio
async def test_post_command_ws_disconnected() -> None:
    app, state, ap_client = _make_app()
    ap_client.ws_connected = False

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post("/commands", json={"command": "/release Alice"})
        assert resp.status_code == 503


@pytest.mark.asyncio
async def test_post_command_forwards_to_ws() -> None:
    app, state, ap_client = _make_app()
    ap_client.ws_connected = True
    ap_client._ws = AsyncMock()
    ap_client._ws.send = AsyncMock()

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        await client.post("/commands", json={"command": "/release Alice"})

    call_args = ap_client._ws.send.call_args[0][0]
    packets = json.loads(call_args)
    assert len(packets) == 1
    assert packets[0]["cmd"] == "Say"
    assert packets[0]["text"] == "/release Alice"


@pytest.mark.asyncio
async def test_post_command_with_admin_password_sends_login_first() -> None:
    app, state, ap_client = _make_app()
    ap_client.ws_connected = True
    ap_client._ws = AsyncMock()
    ap_client._ws.send = AsyncMock()

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        await client.post(
            "/commands",
            json={"command": "!forcegoal Alice"},
            headers={"X-Ap-Admin-Password": "secret"},
        )

    assert ap_client._ws.send.call_count == 2
    login_payload = json.loads(ap_client._ws.send.call_args_list[0][0][0])
    assert login_payload[0]["text"] == "!admin login secret"
    cmd_payload = json.loads(ap_client._ws.send.call_args_list[1][0][0])
    assert cmd_payload[0]["text"] == "!forcegoal Alice"


@pytest.mark.asyncio
async def test_post_command_without_admin_password_sends_one_message() -> None:
    app, state, ap_client = _make_app()
    ap_client.ws_connected = True
    ap_client._ws = AsyncMock()
    ap_client._ws.send = AsyncMock()

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        await client.post("/commands", json={"command": "/say hello"})

    assert ap_client._ws.send.call_count == 1


# ---------------------------------------------------------------------------
# GET /hints/{slot} (legacy route)
# ---------------------------------------------------------------------------

def _populate_store(ap_client: ArchipelagoClient, game: str = "Hollow Knight") -> None:
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
async def test_get_hints_returns_422_for_invalid_slot() -> None:
    app, state, ap_client = _make_app()
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/hints/not-a-number")
        assert resp.status_code == 422


@pytest.mark.asyncio
async def test_get_hints_empty_when_no_hints() -> None:
    app, state, ap_client = _make_app()
    state.ensure_slot(1)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/hints/1")
        assert resp.status_code == 200
        data = resp.json()
        assert data["slot"] == 1
        assert data["hints"] == []
        assert data["hintsUsed"] == 0


@pytest.mark.asyncio
async def test_get_hints_resolves_names_after_merge_from_save() -> None:
    app, state, ap_client = _make_app()
    _populate_store(ap_client)

    unresolved_hint = HintInfo(
        receiving_player=1,
        finding_player=1,
        location_id=8003,
        item_id=8149,
        entrance="",
        item_flags=0,
        status=0,
        item_name="Item #8149",
        location_name="Location #8003",
    )
    state.add_hint(1, unresolved_hint)

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/hints/1")
        assert resp.status_code == 200
        data = resp.json()

    assert len(data["hints"]) == 1
    hint = data["hints"][0]
    assert hint["itemName"] == "Grub"
    assert hint["locationName"] == "Fungal Wastes - Cornifer"
    assert hint["receivingPlayerName"] == "Alice"


@pytest.mark.asyncio
async def test_get_hints_includes_hint_cost_and_budget() -> None:
    app, state, ap_client = _make_app()
    ps = state.ensure_slot(1)
    ps.hint_cost = 91
    ps.hints_used = 3
    ps.checks_done = 50
    ps.hint_points_per_check = 1

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/hints/1")
        data = resp.json()

    assert data["hintCost"] == 91
    assert data["hintsUsed"] == 3
    assert data["hintPointsAvailable"] == 0


@pytest.mark.asyncio
async def test_add_hint_does_not_modify_hints_used() -> None:
    app, state, ap_client = _make_app()
    ps = state.ensure_slot(1)
    ps.hints_used = 5

    hint = HintInfo(
        receiving_player=1, finding_player=1, location_id=8003, item_id=8149,
        entrance="", item_flags=0, status=0,
    )
    state.add_hint(1, hint)
    state.add_hint(1, HintInfo(
        receiving_player=1, finding_player=1, location_id=8004, item_id=8150,
        entrance="", item_flags=0, status=0,
    ))

    assert ps.hints_used == 5


# ---------------------------------------------------------------------------
# GET /item-locations/{slot} (legacy route)
# ---------------------------------------------------------------------------

def _seed_reachable_cache(sender_slot: int, checks: list[dict]) -> None:
    _reachable_cache[sender_slot] = (
        (0, 0),
        {
            "reachable_unchecked": [],
            "reachable_checked": [],
            "unreachable_unchecked": [],
            "checked_unreachable": [],
            **checks,
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
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/item-locations/not-a-number")
        assert resp.status_code == 422


@pytest.mark.asyncio
async def test_get_item_locations_empty_when_cache_is_empty() -> None:
    _reachable_cache.clear()

    app, state, ap_client = _make_app()
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/item-locations/1")
        assert resp.status_code == 200
        data = resp.json()
        assert data["slot"] == 1
        assert data["locations"] == []


@pytest.mark.asyncio
async def test_get_item_locations_same_game_item() -> None:
    _reachable_cache.clear()

    app, state, ap_client = _make_app()
    ap_client._store.handle_connected({
        "players": [{"slot": 1, "alias": "Alice", "name": "Alice_HK"}],
        "slot_info": {"1": {"game": "Hollow Knight"}},
    })
    _seed_reachable_cache(1, {"reachable_unchecked": [
        _make_check(8003, "Fungal Wastes - Cornifer", 8149, "Grub", 1),
    ]})

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/item-locations/1")
        data = resp.json()

    assert len(data["locations"]) == 1
    loc = data["locations"][0]
    assert loc["itemId"] == 8149
    assert loc["checkStatus"] == "reachable"


@pytest.mark.asyncio
async def test_get_item_locations_cross_game_item() -> None:
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

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/item-locations/1")
        data = resp.json()

    assert len(data["locations"]) == 1
    loc = data["locations"][0]
    assert loc["checkStatus"] == "blocked"


@pytest.mark.asyncio
async def test_get_item_locations_check_status_mapping() -> None:
    _reachable_cache.clear()

    app, state, ap_client = _make_app()
    _seed_reachable_cache(1, {
        "reachable_unchecked":   [_make_check(1, "Loc A", 100, "Item A", 1)],
        "reachable_checked":     [_make_check(2, "Loc B", 101, "Item B", 1)],
        "unreachable_unchecked": [_make_check(3, "Loc C", 102, "Item C", 1)],
        "checked_unreachable":   [_make_check(4, "Loc D", 103, "Item D", 1)],
    })

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/item-locations/1")
        data = resp.json()

    by_loc = {loc["locationId"]: loc["checkStatus"] for loc in data["locations"]}
    assert by_loc[1] == "reachable"
    assert by_loc[2] == "checked"
    assert by_loc[3] == "blocked"
    assert by_loc[4] == "checked"


@pytest.mark.asyncio
async def test_get_item_locations_filters_out_other_slots_items() -> None:
    _reachable_cache.clear()

    app, state, ap_client = _make_app()
    _seed_reachable_cache(1, {"reachable_unchecked": [
        _make_check(8003, "Loc for Alice", 8149, "Grub", 1),
        _make_check(8004, "Loc for Bob",   8200, "Shade Soul", 2),
    ]})

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/item-locations/1")
        data = resp.json()

    assert len(data["locations"]) == 1
    assert data["locations"][0]["locationName"] == "Loc for Alice"


# ---------------------------------------------------------------------------
# GET /spheres — admin only
# ---------------------------------------------------------------------------

AUTH_HDR = {"Authorization": "Bearer test-token"}


def _sphere_loc(loc_id: int, loc_name: str, item_id: int, item_name: str, recv_slot: int) -> dict:
    return {
        "id": loc_id,
        "name": loc_name,
        "item": {
            "id": item_id,
            "name": item_name,
            "flags": 0,
            "slot": recv_slot,
            "slot_name": f"Player {recv_slot}",
        },
        "check_status": "reachable",
    }


@pytest.mark.asyncio
async def test_spheres_requires_auth() -> None:
    _reachable_cache.clear()
    app, _, _ = _make_app()
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        resp = await c.get("/spheres")
    assert resp.status_code == 401


@pytest.mark.asyncio
async def test_spheres_returns_not_cached_when_cache_empty() -> None:
    _reachable_cache.clear()
    app, _, _ = _make_app()
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        resp = await c.get("/spheres", headers=AUTH_HDR)
    assert resp.status_code == 200
    data = resp.json()
    assert data["cached"] is False
    assert data["spheres"] == []


@pytest.mark.asyncio
async def test_spheres_aggregates_from_cache() -> None:
    _reachable_cache.clear()

    app, _, ap_client = _make_app()
    ap_client._store.handle_connected({
        "players": [{"slot": 1, "alias": "Alice"}],
        "slot_info": {"1": {"game": "HK"}},
    })
    _seed_reachable_cache(1, {
        "spheres": [
            {
                "index": 0,
                "status": "current",
                "counts": {"total": 2, "checked": 0, "reachable": 2, "blocked": 0},
                "locations": [
                    _sphere_loc(8003, "Fungal Wastes", 8149, "Grub", 1),
                    _sphere_loc(8004, "Crossroads", 8150, "Mask Shard", 1),
                ],
            },
            {
                "index": 1,
                "status": "future",
                "counts": {"total": 1, "checked": 0, "reachable": 0, "blocked": 1},
                "locations": [
                    _sphere_loc(8010, "City of Tears", 8200, "Crystal Heart", 1),
                ],
            },
        ],
    })

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        resp = await c.get("/spheres", headers=AUTH_HDR)
    assert resp.status_code == 200
    data = resp.json()
    assert data["cached"] is True
    assert len(data["spheres"]) == 2

    s0 = data["spheres"][0]
    assert s0["index"] == 0
    assert len(s0["locations"]) == 2
    loc_names = {loc["locationName"] for loc in s0["locations"]}
    assert loc_names == {"Fungal Wastes", "Crossroads"}

    s1 = data["spheres"][1]
    assert s1["index"] == 1
    assert s1["locations"][0]["locationName"] == "City of Tears"
