"""Behavior tests for extracted REST handlers."""
from __future__ import annotations

from unittest.mock import AsyncMock, MagicMock, patch

import pytest
from fastapi.routing import APIRoute
from httpx import ASGITransport, AsyncClient

from bridge.bridge import (
    ArchipelagoClient,
    Config,
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
    ap_client.send_packet = AsyncMock()

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

    ap_client.send_packet.assert_awaited_once()


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
        ("POST", "/commands"),
        ("POST", "/pause"),
        ("POST", "/resume"),
        ("POST", "/deathlink"),
        ("GET", "/slots/{slot}/hints"),
        ("POST", "/slots/{slot}/hints/request"),
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
