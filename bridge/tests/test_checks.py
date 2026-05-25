"""Tests for GET /slots/{slot}/checks and GET /slots/{slot}/items."""
from __future__ import annotations

from unittest.mock import AsyncMock

import pytest
from httpx import ASGITransport, AsyncClient

from bridge.bridge import ArchipelagoClient, Config, StateManager, create_app


def _make_app() -> tuple[object, StateManager, ArchipelagoClient]:
    config = Config(session_id="run-1", internal_token="secret")
    state = StateManager()
    ap_client = ArchipelagoClient(config, state, AsyncMock())
    app = create_app(state, ap_client)
    return app, state, ap_client


def _populate(ap_client: ArchipelagoClient) -> None:
    ap_client._store.handle_data_package({
        "data": {
            "games": {
                "HK": {
                    "item_name_to_id": {"Grub": 101, "Charm": 102},
                    "location_name_to_id": {"Fungal": 201, "City": 202},
                },
                "ALTTP": {
                    "item_name_to_id": {"Sword": 301},
                    "location_name_to_id": {"Link House": 401},
                },
            }
        }
    })
    ap_client._store.handle_connected({
        "players": [
            {"slot": 1, "alias": "Alice"},
            {"slot": 2, "alias": "Bob"},
        ],
        "slot_info": {
            "1": {"game": "HK"},
            "2": {"game": "ALTTP"},
        },
    })


AUTH = {"Authorization": "Bearer secret"}


# ---------------------------------------------------------------------------
# GET /slots/{slot}/checks — public (no auth)
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_checks_public_no_item_info() -> None:
    app, state, ap_client = _make_app()
    _populate(ap_client)
    state.add_location_checks(1, [201])

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        resp = await c.get("/slots/1/checks")
    assert resp.status_code == 200
    data = resp.json()
    assert data["slot"] == 1
    assert data["total"] == 2
    assert data["checkedCount"] == 1
    locs = {loc["locationId"]: loc for loc in data["locations"]}
    assert locs[201]["checked"] is True
    assert locs[202]["checked"] is False
    assert locs[201]["item"] is None  # no spoiler without auth
    assert locs[202]["item"] is None


@pytest.mark.asyncio
async def test_checks_empty_slot_returns_empty_locations() -> None:
    app, _, ap_client = _make_app()
    # no data package loaded → no locations known
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        resp = await c.get("/slots/99/checks")
    assert resp.status_code == 200
    assert resp.json()["total"] == 0
    assert resp.json()["locations"] == []


# ---------------------------------------------------------------------------
# GET /slots/{slot}/checks — admin (with auth + placements)
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_checks_admin_shows_item_content() -> None:
    app, state, ap_client = _make_app()
    _populate(ap_client)
    # Slot 1 world: Fungal contains Grub (for Alice/slot1), City contains Sword (for Bob/slot2)
    ap_client._placements = {
        1: {
            201: (101, 1),  # Fungal → Grub → Alice
            202: (301, 2),  # City → Sword → Bob
        }
    }
    state.add_location_checks(1, [201])

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        resp = await c.get("/slots/1/checks", headers=AUTH)
    assert resp.status_code == 200
    locs = {loc["locationId"]: loc for loc in resp.json()["locations"]}

    grub_loc = locs[201]
    assert grub_loc["checked"] is True
    assert grub_loc["item"]["id"] == 101
    assert grub_loc["item"]["name"] == "Grub"
    assert grub_loc["item"]["receivingSlot"] == 1
    assert grub_loc["item"]["receivingPlayerName"] == "Alice"

    sword_loc = locs[202]
    assert sword_loc["checked"] is False
    assert sword_loc["item"]["id"] == 301
    assert sword_loc["item"]["receivingSlot"] == 2
    assert sword_loc["item"]["receivingPlayerName"] == "Bob"


@pytest.mark.asyncio
async def test_checks_admin_location_without_placement_has_no_item() -> None:
    app, _, ap_client = _make_app()
    _populate(ap_client)
    ap_client._placements = {}  # spoiler not loaded

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        resp = await c.get("/slots/1/checks", headers=AUTH)
    for loc in resp.json()["locations"]:
        assert loc["item"] is None


# ---------------------------------------------------------------------------
# GET /slots/{slot}/items — public
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_items_public_shows_only_received() -> None:
    app, state, ap_client = _make_app()
    _populate(ap_client)
    ps = state.ensure_slot(1)
    # Alice received Grub (from slot 1, loc 201) and Charm (from slot 2, loc 401)
    ps._received_items = [(101, 1, 201), (102, 2, 401)]
    ps.items_received = 2

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        resp = await c.get("/slots/1/items")
    assert resp.status_code == 200
    data = resp.json()
    assert data["receivedCount"] == 2
    assert data["totalOwned"] == 2
    ids = {it["id"] for it in data["items"]}
    assert ids == {101, 102}
    for it in data["items"]:
        assert it["received"] is True
        assert it["foundAt"] is None  # no spoiler without auth


@pytest.mark.asyncio
async def test_items_public_empty_when_nothing_received() -> None:
    app, state, ap_client = _make_app()
    _populate(ap_client)
    state.ensure_slot(1)

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        resp = await c.get("/slots/1/items")
    data = resp.json()
    assert data["receivedCount"] == 0
    assert data["items"] == []


# ---------------------------------------------------------------------------
# GET /slots/{slot}/items — admin
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_items_admin_shows_all_with_location() -> None:
    app, state, ap_client = _make_app()
    _populate(ap_client)
    # Alice owns: Grub at slot1/Fungal (received), Charm at slot2/LinkHouse (not received)
    ap_client._placements = {
        1: {201: (101, 1)},   # slot1/Fungal → Grub → Alice
        2: {401: (102, 1)},   # slot2/LinkHouse → Charm → Alice
    }
    ps = state.ensure_slot(1)
    ps._received_items = [(101, 1, 201)]
    ps.items_received = 1
    # Mark slot1/Fungal as checked
    state.add_location_checks(1, [201])

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        resp = await c.get("/slots/1/items", headers=AUTH)
    assert resp.status_code == 200
    data = resp.json()
    assert data["totalOwned"] == 2
    assert data["receivedCount"] == 1

    by_id = {it["id"]: it for it in data["items"]}

    grub = by_id[101]
    assert grub["received"] is True
    assert grub["foundAt"]["findingSlot"] == 1
    assert grub["foundAt"]["locationName"] == "Fungal"
    assert grub["foundAt"]["checked"] is True

    charm = by_id[102]
    assert charm["received"] is False
    assert charm["foundAt"]["findingSlot"] == 2
    assert charm["foundAt"]["locationName"] == "Link House"
    assert charm["foundAt"]["checked"] is False


@pytest.mark.asyncio
async def test_items_admin_cross_world_item() -> None:
    """An item belonging to slot 1 found in slot 2's world."""
    app, state, ap_client = _make_app()
    _populate(ap_client)
    ap_client._placements = {
        2: {401: (101, 1)},  # Bob's world, LinkHouse → Grub → Alice
    }
    state.ensure_slot(1)

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        resp = await c.get("/slots/1/items", headers=AUTH)
    data = resp.json()
    assert data["totalOwned"] == 1
    item = data["items"][0]
    assert item["id"] == 101
    assert item["received"] is False
    assert item["foundAt"]["findingSlot"] == 2
    assert item["foundAt"]["findingPlayerName"] == "Bob"
    assert item["foundAt"]["locationName"] == "Link House"
    assert item["foundAt"]["checked"] is False


@pytest.mark.asyncio
async def test_items_admin_flags_populated_from_store() -> None:
    app, state, ap_client = _make_app()
    _populate(ap_client)
    ap_client._store.record_item_flags(101, 1)  # Grub is progression
    ap_client._placements = {1: {201: (101, 1)}}
    state.ensure_slot(1)

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        resp = await c.get("/slots/1/items", headers=AUTH)
    item = resp.json()["items"][0]
    assert item["flags"] == 1


# ---------------------------------------------------------------------------
# GET /slots/{slot} — single slot detail
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_slot_detail_not_found() -> None:
    app, _, _ = _make_app()
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        resp = await c.get("/slots/99")
    assert resp.status_code == 404


@pytest.mark.asyncio
async def test_slot_detail_returns_budget_and_fields() -> None:
    app, state, ap_client = _make_app()
    _populate(ap_client)
    state.set_slot_name(1, "Alice")
    ps = state.ensure_slot(1)
    state.set_checks_total(1, 10)
    state.add_location_checks(1, [201, 202])
    ps.hint_points_per_check = 2
    ps.hints_used = 1
    ps.hint_cost = 2

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        resp = await c.get("/slots/1")
    assert resp.status_code == 200
    data = resp.json()
    assert data["slot"] == 1
    assert data["name"] == "Alice"
    assert data["game"] == "HK"
    assert data["checksDone"] == 2
    assert data["checksTotal"] == 10
    # budget = checks_done * hint_points_per_check - hints_used * hint_cost = 2*2 - 1*2 = 2
    assert data["budget"] == 2
    assert data["goalReachedAt"] is None


# ---------------------------------------------------------------------------
# GET /slots/{slot}/items/missing — admin only
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_missing_items_requires_auth() -> None:
    app, state, ap_client = _make_app()
    _populate(ap_client)
    state.ensure_slot(1)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        resp = await c.get("/slots/1/items/missing")
    assert resp.status_code == 401


@pytest.mark.asyncio
async def test_missing_items_returns_unreceived() -> None:
    app, state, ap_client = _make_app()
    _populate(ap_client)
    # Alice owns: Grub at slot1/Fungal (received), Charm at slot2/LinkHouse (not received)
    ap_client._placements = {
        1: {201: (101, 1)},   # slot1/Fungal → Grub → Alice
        2: {401: (102, 1)},   # slot2/LinkHouse → Charm → Alice
    }
    ps = state.ensure_slot(1)
    ps._received_items = [(101, 1, 201)]  # Grub received

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        resp = await c.get("/slots/1/items/missing", headers=AUTH)
    assert resp.status_code == 200
    data = resp.json()
    assert data["slot"] == 1
    assert len(data["missing"]) == 1
    m = data["missing"][0]
    assert m["itemId"] == 102
    assert m["itemName"] == "Charm"
    assert m["locationId"] == 401
    assert m["locationName"] == "Link House"
    assert m["receivingSlot"] == 1
    assert m["receivingPlayerName"] == "Alice"


@pytest.mark.asyncio
async def test_missing_items_empty_when_all_received() -> None:
    app, state, ap_client = _make_app()
    _populate(ap_client)
    ap_client._placements = {1: {201: (101, 1)}}
    ps = state.ensure_slot(1)
    ps._received_items = [(101, 1, 201)]

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        resp = await c.get("/slots/1/items/missing", headers=AUTH)
    assert resp.json()["missing"] == []


# ---------------------------------------------------------------------------
# GET /slots/{slot}/spoiler — admin only
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_slot_spoiler_requires_auth() -> None:
    app, state, ap_client = _make_app()
    _populate(ap_client)
    state.ensure_slot(1)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        resp = await c.get("/slots/1/spoiler")
    assert resp.status_code == 401


@pytest.mark.asyncio
async def test_slot_spoiler_lists_all_placements_in_world() -> None:
    app, state, ap_client = _make_app()
    _populate(ap_client)
    # Slot 1 world: Fungal → Grub (Alice), City → Sword (Bob)
    ap_client._placements = {
        1: {
            201: (101, 1),  # Fungal → Grub → Alice
            202: (301, 2),  # City → Sword → Bob
        }
    }
    state.ensure_slot(1)

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        resp = await c.get("/slots/1/spoiler", headers=AUTH)
    assert resp.status_code == 200
    data = resp.json()
    placements = {p["locationId"]: p for p in data["placements"]}

    assert placements[201]["itemName"] == "Grub"
    assert placements[201]["receivingSlot"] == 1
    assert placements[201]["receivingPlayerName"] == "Alice"

    assert placements[202]["itemName"] == "Sword"
    assert placements[202]["receivingSlot"] == 2
    assert placements[202]["receivingPlayerName"] == "Bob"


@pytest.mark.asyncio
async def test_slot_spoiler_empty_when_no_placements() -> None:
    app, _, ap_client = _make_app()
    _populate(ap_client)
    ap_client._placements = {}

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        resp = await c.get("/slots/1/spoiler", headers=AUTH)
    assert resp.status_code == 200
    assert resp.json()["placements"] == []
