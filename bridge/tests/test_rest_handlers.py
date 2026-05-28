"""Behavior tests for extracted REST handlers."""
from __future__ import annotations

import collections
from unittest.mock import AsyncMock, MagicMock, patch

import pytest
from fastapi.routing import APIRoute
from httpx import ASGITransport, AsyncClient

from bridge.bridge import (
    ArchipelagoClient,
    Config,
    HintInfo,
    StateManager,
    create_app,
)
from bridge.core import rest_session
from bridge.core import rest_reachable


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _config(**overrides: object) -> Config:
    defaults: dict[str, object] = {
        "internal_token": "test-token",
        "ap_pid_file": "/tmp/test-ap.pid",
    }
    defaults.update(overrides)
    return Config(  # type: ignore[arg-type]
        session_id="run-1",
        **defaults,
    )


def _make_app(config: Config | None = None) -> tuple[object, StateManager, ArchipelagoClient]:
    cfg = config or _config()
    state = StateManager()
    broadcast = AsyncMock()
    ap_client = ArchipelagoClient(cfg, state, broadcast)
    app = create_app(state, ap_client)
    return app, state, ap_client


# ---------------------------------------------------------------------------
# health
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_health_returns_ok_when_ws_connected() -> None:
    app, _, ap_client = _make_app()
    ap_client.ws_connected = True

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/health")
        assert resp.status_code == 200
        data = resp.json()
        assert data["status"] == "ok"
        assert data["wsConnected"] is True


@pytest.mark.asyncio
async def test_health_returns_ok_when_ws_disconnected() -> None:
    app, _, ap_client = _make_app()
    ap_client.ws_connected = False

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/health")
        assert resp.status_code == 200
        data = resp.json()
        assert data["status"] == "ok"
        assert data["wsConnected"] is False


# ---------------------------------------------------------------------------
# post_command
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_post_command_success() -> None:
    app, _, ap_client = _make_app()
    ap_client.ws_connected = True
    ap_client.send_command = AsyncMock()

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post("/commands", json={"command": "/say hello"})
        assert resp.status_code == 200
        data = resp.json()
        assert data["ok"] is True

    ap_client.send_command.assert_awaited_once_with("/say hello")


@pytest.mark.asyncio
async def test_post_command_ws_disconnected_returns_503() -> None:
    app, _, ap_client = _make_app()
    ap_client.ws_connected = False

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post("/commands", json={"command": "/say hello"})
        assert resp.status_code == 503
        data = resp.json()
        assert data["error"] == "ws_disconnected"


# ---------------------------------------------------------------------------
# request_hint (legacy /hints/{slot}/request)
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_request_hint_success_free() -> None:
    app, _, ap_client = _make_app()
    ap_client.ws_connected = True
    ap_client.send_admin_command = AsyncMock()
    ap_client._broadcast_hints = AsyncMock()
    # Populate store so location name lookup succeeds
    ap_client._store._slot_games[1] = "TestGame"
    ap_client._store._location_names["TestGame"] = {42: "Test Location"}

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post(
            "/hints/1/request",
            json={"locationId": 42, "free": True},
        )
        assert resp.status_code == 200
        data = resp.json()
        assert data["ok"] is True
        assert data["slot"] == 1
        assert data["locationId"] == 42
        assert data["free"] is True

    ap_client.send_admin_command.assert_awaited_once()


@pytest.mark.asyncio
async def test_request_hint_missing_location_id_returns_422() -> None:
    app, _, ap_client = _make_app()
    ap_client.ws_connected = True

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post("/hints/1/request", json={"free": True})
        assert resp.status_code == 422


@pytest.mark.asyncio
async def test_request_hint_non_integer_slot_returns_422() -> None:
    app, _, ap_client = _make_app()

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post("/hints/abc/request", json={"locationId": 42})
        assert resp.status_code == 422


# ---------------------------------------------------------------------------
# get_feed
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_get_feed_empty() -> None:
    app, _, _ = _make_app()

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/feed")
        assert resp.status_code == 200
        data = resp.json()
        assert data["events"] == []


@pytest.mark.asyncio
async def test_get_feed_returns_events_newest_last() -> None:
    app, _, ap_client = _make_app()
    ap_client._feed_events = collections.deque(
        [{"type": "chat", "text": f"msg{i}"} for i in range(5)],
        maxlen=200,
    )

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/feed?limit=3")
        assert resp.status_code == 200
        events = resp.json()["events"]
        assert len(events) == 3
        assert events[-1]["text"] == "msg4"


# ---------------------------------------------------------------------------
# get_data_package
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_get_data_package_index() -> None:
    app, _, ap_client = _make_app()
    ap_client._store._item_names["TestGame"] = {1: "Sword"}
    ap_client._store._location_names["TestGame"] = {100: "Cave"}

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/data-package")
        assert resp.status_code == 200
        assert "TestGame" in resp.json()["games"]


@pytest.mark.asyncio
async def test_get_data_package_game() -> None:
    app, _, ap_client = _make_app()
    ap_client._store._item_names["TestGame"] = {1: "Sword", 2: "Shield"}
    ap_client._store._location_names["TestGame"] = {100: "Cave"}

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/data-package/TestGame")
        assert resp.status_code == 200
        data = resp.json()
        assert data["game"] == "TestGame"
        assert data["items"]["1"] == "Sword"
        assert data["locations"]["100"] == "Cave"


@pytest.mark.asyncio
async def test_get_data_package_game_not_found() -> None:
    app, _, _ = _make_app()

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/data-package/UnknownGame")
        assert resp.status_code == 404


# ---------------------------------------------------------------------------
# get_reachable (legacy /reachable/{slot})
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_get_reachable_success() -> None:
    app, state, ap_client = _make_app()
    ap_client._broadcast_state_changed = AsyncMock()

    mock_result = {"player": "Tester", "counts": {"reachable_now": 5}, "cached": False}

    with patch.object(rest_reachable, "_compute_reachable", new=AsyncMock(return_value=(mock_result, ""))):
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            resp = await client.get("/reachable/1")
            assert resp.status_code == 200
            data = resp.json()
            assert data["player"] == "Tester"


@pytest.mark.asyncio
async def test_get_reachable_non_integer_slot_returns_422() -> None:
    app, _, _ = _make_app()

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/reachable/abc")
        assert resp.status_code == 422


# ---------------------------------------------------------------------------
# Route parity test
# ---------------------------------------------------------------------------

def test_route_parity() -> None:
    app = create_app(MagicMock(), MagicMock())
    registered: set[tuple[str, str]] = set()
    for r in app.routes:
        if isinstance(r, APIRoute):
            for method in (r.methods or set()):
                if method not in ("HEAD", "OPTIONS"):
                    registered.add((method, r.path))

    required = {
        ("GET", "/health"),
        ("GET", "/room"),
        ("GET", "/slots"),
        ("GET", "/state"),
        ("GET", "/feed"),
        ("GET", "/data-package"),
        ("GET", "/data-package/{game}"),
        ("POST", "/commands"),
        ("POST", "/pause"),
        ("POST", "/resume"),
        ("POST", "/deathlink"),
        ("GET", "/slots/{slot}/hints"),
        ("POST", "/slots/{slot}/hints/request"),
        ("PATCH", "/slots/{slot}/hints/{location_id}"),
        ("GET", "/slots/{slot}/checks"),
        ("GET", "/slots/{slot}/items"),
        ("GET", "/slots/{slot}/reachable"),
        ("GET", "/slots/{slot}/item-locations"),
        # Legacy routes kept for backward compatibility
        ("GET", "/hints/{slot}"),
        ("POST", "/hints/{slot}/request"),
        ("GET", "/reachable/{slot}"),
        ("GET", "/item-locations/{slot}"),
    }
    assert required.issubset(registered), f"Missing routes: {required - registered}"


# ---------------------------------------------------------------------------
# post_pause auth
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_post_pause_valid_token_returns_200() -> None:
    app, _, ap_client = _make_app()
    ap_client.ws_connected = False

    with patch.object(rest_session, "_pause_flow", new=AsyncMock()):
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            resp = await client.post(
                "/pause",
                headers={"Authorization": "Bearer test-token"},
            )
            assert resp.status_code == 200
            data = resp.json()
            assert data["ok"] is True


@pytest.mark.asyncio
async def test_post_pause_no_auth_returns_401() -> None:
    app, _, _ = _make_app()

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post("/pause")
        assert resp.status_code == 401
        data = resp.json()
        assert data["error"] == "unauthorized"


@pytest.mark.asyncio
async def test_post_pause_wrong_token_returns_401() -> None:
    app, _, _ = _make_app()

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post(
            "/pause",
            headers={"Authorization": "Bearer wrong-token"},
        )
        assert resp.status_code == 401
        data = resp.json()
        assert data["error"] == "unauthorized"


# ---------------------------------------------------------------------------
# update_hint_status (PATCH /slots/{slot}/hints/{location_id})
# ---------------------------------------------------------------------------

def _seed_hint(ap_client: ArchipelagoClient, state: StateManager, slot: int, location_id: int, status: int = 0) -> None:
    hint = HintInfo(
        receiving_player=slot,
        finding_player=slot,
        location_id=location_id,
        item_id=9001,
        entrance="",
        item_flags=0,
        status=status,
        item_name="Sword",
        location_name="Cave",
    )
    state.add_hint(slot, hint)


@pytest.mark.asyncio
async def test_update_hint_status_success() -> None:
    app, state, ap_client = _make_app()
    ap_client.ws_connected = True
    ap_client.send_packet = AsyncMock()
    ap_client._broadcast_hints = AsyncMock()
    _seed_hint(ap_client, state, slot=1, location_id=42)

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.patch("/slots/1/hints/42", json={"status": 30})
        assert resp.status_code == 200
        data = resp.json()
        assert data["ok"] is True
        assert data["slot"] == 1
        assert data["locationId"] == 42

    ap_client.send_packet.assert_awaited_once_with({
        "cmd": "UpdateHint",
        "player": 1,
        "location": 42,
        "status": 30,
    })
    ap_client._broadcast_hints.assert_awaited_once_with(1)

    hints = state.get_hints(1)
    assert hints[0].status == 30


@pytest.mark.asyncio
async def test_update_hint_status_invalid_status_returns_422() -> None:
    app, state, ap_client = _make_app()
    ap_client.ws_connected = True
    _seed_hint(ap_client, state, slot=1, location_id=42)

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.patch("/slots/1/hints/42", json={"status": 99})
        assert resp.status_code == 422


@pytest.mark.asyncio
async def test_update_hint_status_hint_not_found_returns_404() -> None:
    app, state, ap_client = _make_app()
    ap_client.ws_connected = True

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.patch("/slots/1/hints/999", json={"status": 30})
        assert resp.status_code == 404


@pytest.mark.asyncio
async def test_update_hint_status_ws_disconnected_returns_503() -> None:
    app, state, ap_client = _make_app()
    ap_client.ws_connected = False
    _seed_hint(ap_client, state, slot=1, location_id=42)

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.patch("/slots/1/hints/42", json={"status": 10})
        assert resp.status_code == 503
        data = resp.json()
        assert data["error"] == "ws_disconnected"
