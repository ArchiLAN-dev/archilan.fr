"""Behavior tests for extracted REST handlers (Story 20.4)."""
from __future__ import annotations

from unittest.mock import AsyncMock, MagicMock, patch

import pytest
from aiohttp.test_utils import TestClient, TestServer

from bridge.bridge import (
    ArchipelagoClient,
    Config,
    MercurePublisher,
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
        "bridge_internal_token": "test-token",
        "ap_pid_file": "/tmp/test-ap.pid",
    }
    defaults.update(overrides)
    return Config(  # type: ignore[arg-type]
        mercure_hub_url="http://hub.test",
        central_api_secret="s",
        symfony_internal_url="http://api.test",
        run_id="run-1",
        **defaults,
    )


def _make_app(config: Config | None = None) -> tuple[object, StateManager, ArchipelagoClient]:
    cfg = config or _config()
    state = StateManager()
    publisher = MagicMock(spec=MercurePublisher)
    publisher.publish = AsyncMock()
    ap_client = ArchipelagoClient(cfg, state, publisher)
    app = create_app(state, ap_client)
    return app, state, ap_client


# ---------------------------------------------------------------------------
# Task 7a: health (rest_session) - success path
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_health_returns_ok_when_ws_connected() -> None:
    app, _, ap_client = _make_app()
    ap_client.ws_connected = True

    async with TestClient(TestServer(app)) as client:
        resp = await client.get("/health")
        assert resp.status == 200
        data = await resp.json()
        assert data["status"] == "ok"
        assert data["ws_connected"] is True


@pytest.mark.asyncio
async def test_health_returns_ok_when_ws_disconnected() -> None:
    app, _, ap_client = _make_app()
    ap_client.ws_connected = False

    async with TestClient(TestServer(app)) as client:
        resp = await client.get("/health")
        assert resp.status == 200
        data = await resp.json()
        assert data["status"] == "ok"
        assert data["ws_connected"] is False


# ---------------------------------------------------------------------------
# Task 7b: post_command (rest_session) - success + WS disconnected 503
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_post_command_success() -> None:
    app, _, ap_client = _make_app()
    ap_client.ws_connected = True
    ap_client.send_command = AsyncMock()

    async with TestClient(TestServer(app)) as client:
        resp = await client.post("/commands", json={"command": "/say hello"})
        assert resp.status == 200
        data = await resp.json()
        assert data["ok"] is True

    ap_client.send_command.assert_awaited_once_with("/say hello")


@pytest.mark.asyncio
async def test_post_command_ws_disconnected_returns_503() -> None:
    app, _, ap_client = _make_app()
    ap_client.ws_connected = False

    async with TestClient(TestServer(app)) as client:
        resp = await client.post("/commands", json={"command": "/say hello"})
        assert resp.status == 503
        data = await resp.json()
        assert data["error"] == "ws_disconnected"


# ---------------------------------------------------------------------------
# Task 7c: request_hint (rest_hints) - success + missing location_id + bad slot
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_request_hint_success_free() -> None:
    app, _, ap_client = _make_app()
    ap_client.ws_connected = True
    ap_client.send_packet = AsyncMock()

    async with TestClient(TestServer(app)) as client:
        resp = await client.post(
            "/hints/1/request",
            json={"location_id": 42, "free": True},
        )
        assert resp.status == 200
        data = await resp.json()
        assert data["ok"] is True
        assert data["slot"] == 1
        assert data["location_id"] == 42
        assert data["free"] is True

    ap_client.send_packet.assert_awaited_once()


@pytest.mark.asyncio
async def test_request_hint_missing_location_id_returns_400() -> None:
    app, _, ap_client = _make_app()
    ap_client.ws_connected = True

    async with TestClient(TestServer(app)) as client:
        resp = await client.post("/hints/1/request", json={"free": True})
        assert resp.status == 400
        data = await resp.json()
        assert "location_id" in data["error"]


@pytest.mark.asyncio
async def test_request_hint_non_integer_slot_returns_400() -> None:
    app, _, ap_client = _make_app()

    async with TestClient(TestServer(app)) as client:
        resp = await client.post("/hints/abc/request", json={"location_id": 42})
        assert resp.status == 400
        data = await resp.json()
        assert data["error"] == "invalid slot"


# ---------------------------------------------------------------------------
# Task 7d: get_reachable (rest_reachable) - success + non-integer slot 400
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_get_reachable_success() -> None:
    app, state, ap_client = _make_app()
    ap_client._publish_players = AsyncMock()

    mock_result = {"player": "Tester", "counts": {"reachable_now": 5}, "cached": False}

    with patch.object(rest_reachable, "_compute_reachable", new=AsyncMock(return_value=(mock_result, ""))):
        async with TestClient(TestServer(app)) as client:
            resp = await client.get("/reachable/1")
            assert resp.status == 200
            data = await resp.json()
            assert data["player"] == "Tester"


@pytest.mark.asyncio
async def test_get_reachable_non_integer_slot_returns_400() -> None:
    app, _, _ = _make_app()

    async with TestClient(TestServer(app)) as client:
        resp = await client.get("/reachable/abc")
        assert resp.status == 400
        data = await resp.json()
        assert data["error"] == "invalid slot"


# ---------------------------------------------------------------------------
# Task 7e: Route parity test
# ---------------------------------------------------------------------------

def test_route_parity() -> None:
    app = create_app(MagicMock(), MagicMock())
    registered = {
        (r.method, r.resource.canonical)
        for r in app.router.routes()
        if r.method != "HEAD"
    }
    expected = {
        ("GET", "/health"),
        ("GET", "/state"),
        ("POST", "/commands"),
        ("POST", "/save"),
        ("POST", "/pause"),
        ("POST", "/resume"),
        ("GET", "/hints/{slot}"),
        ("POST", "/hints/{slot}/request"),
        ("GET", "/reachable/{slot}"),
        ("GET", "/item-locations/{slot}"),
    }
    assert registered == expected


# ---------------------------------------------------------------------------
# Task 7f: post_pause (rest_session) - success + unauthorized 401
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_post_pause_valid_token_returns_200() -> None:
    app, _, ap_client = _make_app()
    ap_client.ws_connected = False

    with patch.object(rest_session, "_pause_flow", new=AsyncMock()):
        async with TestClient(TestServer(app)) as client:
            resp = await client.post(
                "/pause",
                headers={"Authorization": "Bearer test-token"},
            )
            assert resp.status == 200
            data = await resp.json()
            assert data["ok"] is True


@pytest.mark.asyncio
async def test_post_pause_no_auth_returns_401() -> None:
    app, _, _ = _make_app()

    async with TestClient(TestServer(app)) as client:
        resp = await client.post("/pause")
        assert resp.status == 401
        data = await resp.json()
        assert data["error"] == "unauthorized"


@pytest.mark.asyncio
async def test_post_pause_wrong_token_returns_401() -> None:
    app, _, _ = _make_app()

    async with TestClient(TestServer(app)) as client:
        resp = await client.post(
            "/pause",
            headers={"Authorization": "Bearer wrong-token"},
        )
        assert resp.status == 401
        data = await resp.json()
        assert data["error"] == "unauthorized"
