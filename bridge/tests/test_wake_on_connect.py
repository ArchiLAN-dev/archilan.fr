"""Tests for WakeOnConnectServer (Story 17.5)."""
from __future__ import annotations

import asyncio
from unittest.mock import AsyncMock, MagicMock

import pytest

from bridge.bridge import (
    ArchipelagoClient,
    Config,
    MercurePublisher,
    StateManager,
)
import rest as _rest
from wake_on_connect import WakeOnConnectServer


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _config(**overrides: object) -> Config:
    defaults: dict[str, object] = {
        "bridge_internal_token": "test-token",
        "ap_pid_file": "/tmp/test-ap.pid",
        "ap_launch_cmd": "python multiserver.py",
        "minio_endpoint": "http://minio.test:9000",
        "minio_access_key": "minioadmin",
        "minio_secret_key": "minioadmin",
        "minio_bucket": "test-bucket",
    }
    defaults.update(overrides)
    return Config(  # type: ignore[arg-type]
        mercure_hub_url="http://hub.test",
        central_api_secret="s",
        symfony_internal_url="http://api.test",
        run_id="run-1",
        **defaults,
    )


async def _free_port() -> int:
    """Find a free TCP port on localhost."""
    server = await asyncio.start_server(lambda r, w: None, "127.0.0.1", 0)
    port = server.sockets[0].getsockname()[1]
    server.close()
    await server.wait_closed()
    return port


# ---------------------------------------------------------------------------
# WakeOnConnectServer unit tests
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_wake_calls_on_connect_when_client_connects() -> None:
    """First TCP connection → on_connect() called exactly once."""
    port = await _free_port()
    stop_event = asyncio.Event()
    called: list[bool] = []

    async def on_connect() -> None:
        called.append(True)

    server = WakeOnConnectServer(port, stop_event, on_connect)
    serve_task = asyncio.create_task(server.serve())

    # Give the server a moment to bind
    await asyncio.sleep(0.05)

    # Connect as a fake player
    try:
        reader, writer = await asyncio.open_connection("127.0.0.1", port)
        writer.close()
        try:
            await writer.wait_closed()
        except Exception:
            pass
    except ConnectionResetError:
        pass  # expected - server closes the socket immediately

    await asyncio.wait_for(serve_task, timeout=5.0)

    assert called == [True]


@pytest.mark.asyncio
async def test_wake_stop_event_prevents_on_connect() -> None:
    """Setting stop_event before any connection → serve() returns, on_connect() NOT called."""
    port = await _free_port()
    stop_event = asyncio.Event()
    called: list[bool] = []

    async def on_connect() -> None:
        called.append(True)

    server = WakeOnConnectServer(port, stop_event, on_connect)
    serve_task = asyncio.create_task(server.serve())

    await asyncio.sleep(0.05)
    stop_event.set()

    await asyncio.wait_for(serve_task, timeout=5.0)

    assert called == []


@pytest.mark.asyncio
async def test_wake_on_connect_called_only_once_for_multiple_connections() -> None:
    """Two rapid connections in → on_connect() called exactly once."""
    port = await _free_port()
    stop_event = asyncio.Event()
    called: list[bool] = []

    async def on_connect() -> None:
        called.append(True)

    server = WakeOnConnectServer(port, stop_event, on_connect)
    serve_task = asyncio.create_task(server.serve())

    await asyncio.sleep(0.05)

    async def try_connect() -> None:
        try:
            reader, writer = await asyncio.open_connection("127.0.0.1", port)
            writer.close()
            try:
                await writer.wait_closed()
            except Exception:
                pass
        except (ConnectionResetError, OSError):
            pass

    # Fire two connections concurrently
    await asyncio.gather(try_connect(), try_connect())

    await asyncio.wait_for(serve_task, timeout=5.0)

    assert len(called) == 1


# ---------------------------------------------------------------------------
# Integration: _pause_flow starts wake task, _resume_flow cancels it
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_pause_flow_starts_wake_task_and_resume_cancels_it(tmp_path: object) -> None:
    """After _pause_flow completes, _wake_task is set; _resume_flow cancels it."""
    save_file = str(tmp_path / "game.apsave")
    with open(save_file, "wb") as f:
        f.write(b"save")

    cfg = _config(save_dir=str(tmp_path))
    state = StateManager()
    publisher = MagicMock(spec=MercurePublisher)
    publisher.publish = AsyncMock()
    ap_client = ArchipelagoClient(cfg, state, publisher)
    ap_client._http = AsyncMock()

    # Reset module state
    _rest._wake_stop_event = None
    _rest._wake_task = None

    with pytest.MonkeyPatch().context() as mp:
        mp.setattr(_rest, "_poll_for_save", AsyncMock(return_value=save_file))
        mp.setattr(_rest, "_upload_save", AsyncMock(return_value="sessions/run-1/saves/ts.apsave"))
        mp.setattr(_rest, "_kill_ap", AsyncMock())
        mp.setattr(_rest, "_notify_paused", AsyncMock())

        # Use a real (but unused) port so the listener can bind
        port = await _free_port()
        mp.setattr(cfg, "archipelago_ws_url", f"ws://localhost:{port}")

        await _rest._pause_flow(ap_client)

    # Wake task should have been created
    assert _rest._wake_task is not None
    assert not _rest._wake_task.done()

    # Now resume should cancel it
    with pytest.MonkeyPatch().context() as mp:
        mp.setattr(_rest, "_find_local_save", AsyncMock(return_value=save_file))
        mp.setattr(_rest, "_launch_ap", AsyncMock(return_value=MagicMock(returncode=None)))
        mp.setattr(_rest, "_wait_for_ap_health", AsyncMock(return_value=True))
        mp.setattr(_rest, "_notify_restarted", AsyncMock())

        await _rest._resume_flow(ap_client, last_save_key=None)

    assert _rest._wake_task is None


@pytest.mark.asyncio
async def test_wake_flow_aborts_when_restarting_callback_fails(tmp_path: object) -> None:
    """If Symfony refuses idle -> restarting, AP must not be relaunched."""
    cfg = _config(save_dir=str(tmp_path))
    state = StateManager()
    publisher = MagicMock(spec=MercurePublisher)
    publisher.publish = AsyncMock()
    ap_client = ArchipelagoClient(cfg, state, publisher)
    ap_client._http = AsyncMock()

    with pytest.MonkeyPatch().context() as mp:
        mock_find = AsyncMock(return_value=str(tmp_path / "game.apsave"))
        mock_launch = AsyncMock()
        mock_failed = AsyncMock()
        mock_restarted = AsyncMock()

        mp.setattr(_rest, "_notify_restarting", AsyncMock(return_value=False))
        mp.setattr(_rest, "_find_local_save", mock_find)
        mp.setattr(_rest, "_launch_ap", mock_launch)
        mp.setattr(_rest, "_notify_restart_failed", mock_failed)
        mp.setattr(_rest, "_notify_restarted", mock_restarted)

        await _rest._wake_on_connect_flow(ap_client)

    mock_find.assert_not_awaited()
    mock_launch.assert_not_awaited()
    mock_failed.assert_not_awaited()
    mock_restarted.assert_not_awaited()
