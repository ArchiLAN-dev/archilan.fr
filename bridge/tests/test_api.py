"""Tests for REST API endpoints (AC #11)."""
from __future__ import annotations

import asyncio
from unittest.mock import AsyncMock, MagicMock

import pytest
from aiohttp.test_utils import TestClient, TestServer

from bridge.bridge import (
    ArchipelagoClient,
    Config,
    MercurePublisher,
    StateManager,
    TokenManager,
    create_app,
)


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
    import json

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
