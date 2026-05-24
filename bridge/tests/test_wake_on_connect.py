"""Tests for WakeOnConnectServer."""
from __future__ import annotations

import asyncio
from unittest.mock import AsyncMock

import pytest

from bridge.bridge import (
    ArchipelagoClient,
    Config,
    StateManager,
)
from bridge.core import rest_session as _rest
from bridge.core.coordinator import PauseResumeCoordinator
from bridge.core.wake_on_connect import WakeOnConnectServer


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _config(**overrides: object) -> Config:
    defaults: dict[str, object] = {
        "internal_token": "test-token",
        "ap_pid_file": "/tmp/test-ap.pid",
        "ap_launch_cmd": "python multiserver.py",
    }
    defaults.update(overrides)
    return Config(  # type: ignore[arg-type]
        session_id="run-1",
        **defaults,
    )


async def _free_port() -> int:
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
    port = await _free_port()
    stop_event = asyncio.Event()
    called: list[bool] = []

    async def on_connect() -> None:
        called.append(True)

    server = WakeOnConnectServer(port, stop_event, on_connect)
    serve_task = asyncio.create_task(server.serve())

    await asyncio.sleep(0.05)

    try:
        reader, writer = await asyncio.open_connection("127.0.0.1", port)
        writer.close()
        try:
            await writer.wait_closed()
        except Exception:
            pass
    except ConnectionResetError:
        pass

    await asyncio.wait_for(serve_task, timeout=5.0)

    assert called == [True]


@pytest.mark.asyncio
async def test_wake_stop_event_prevents_on_connect() -> None:
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

    await asyncio.gather(try_connect(), try_connect())

    await asyncio.wait_for(serve_task, timeout=5.0)

    assert len(called) == 1


# ---------------------------------------------------------------------------
# Integration: _pause_flow starts wake task, _resume_flow cancels it
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_pause_flow_starts_wake_task_and_resume_cancels_it(tmp_path: object) -> None:
    save_file = str(tmp_path / "game.apsave")
    with open(save_file, "wb") as f:
        f.write(b"save")

    cfg = _config(save_dir=str(tmp_path))
    state = StateManager()
    broadcast = AsyncMock()
    ap_client = ArchipelagoClient(cfg, state, broadcast)

    coordinator = PauseResumeCoordinator()
    mock_broadcast = AsyncMock()

    with pytest.MonkeyPatch().context() as mp:
        mp.setattr(_rest, "_poll_for_save", AsyncMock(return_value=save_file))
        mp.setattr(_rest, "_kill_ap", AsyncMock())

        port = await _free_port()
        mp.setattr(cfg, "ap_ws_url", f"ws://localhost:{port}")

        await _rest._pause_flow(ap_client, coordinator, mock_broadcast)

    assert coordinator.wake_task is not None
    assert not coordinator.wake_task.done()

    with pytest.MonkeyPatch().context() as mp:
        mp.setattr(_rest, "_find_local_save", AsyncMock(return_value=save_file))
        mp.setattr(_rest, "_launch_ap", AsyncMock(return_value=AsyncMock(returncode=None)))
        mp.setattr(_rest, "_wait_for_ap_health", AsyncMock(return_value=True))

        await _rest._resume_flow(ap_client, coordinator=coordinator, broadcast=mock_broadcast)

    assert coordinator.wake_task is None


# ---------------------------------------------------------------------------
# _wake_on_connect_flow: no approval → no restart
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_wake_flow_aborts_when_not_approved(tmp_path: object) -> None:
    """If no WS client approves restart, AP must not be relaunched."""
    cfg = _config(save_dir=str(tmp_path))
    state = StateManager()
    broadcast = AsyncMock()
    ap_client = ArchipelagoClient(cfg, state, broadcast)

    coordinator = PauseResumeCoordinator(
        request_approve_restart=AsyncMock(return_value=False),
    )

    mock_find = AsyncMock(return_value=str(tmp_path / "game.apsave"))
    mock_launch = AsyncMock()
    mock_broadcast = AsyncMock()

    with pytest.MonkeyPatch().context() as mp:
        mp.setattr(_rest, "_find_local_save", mock_find)
        mp.setattr(_rest, "_launch_ap", mock_launch)

        await _rest._wake_on_connect_flow(ap_client, coordinator, mock_broadcast)

    mock_find.assert_not_awaited()
    mock_launch.assert_not_awaited()


@pytest.mark.asyncio
async def test_wake_flow_broadcasts_restarted_when_approved(tmp_path: object) -> None:
    """If a WS client approves restart and AP comes up healthy, lifecycle.restarted is broadcast."""
    save_file = str(tmp_path / "game.apsave")
    with open(save_file, "wb") as f:
        f.write(b"save")

    cfg = _config(save_dir=str(tmp_path))
    state = StateManager()
    broadcast = AsyncMock()
    ap_client = ArchipelagoClient(cfg, state, broadcast)

    coordinator = PauseResumeCoordinator(
        request_approve_restart=AsyncMock(return_value=True),
    )
    mock_broadcast_calls: list[str] = []

    async def _mock_broadcast(event_type: str, payload: dict) -> None:
        mock_broadcast_calls.append(payload.get("event", ""))

    with pytest.MonkeyPatch().context() as mp:
        mp.setattr(_rest, "_find_local_save", AsyncMock(return_value=save_file))
        mp.setattr(_rest, "_launch_ap", AsyncMock(return_value=AsyncMock(returncode=None)))
        mp.setattr(_rest, "_wait_for_ap_health", AsyncMock(return_value=True))

        await _rest._wake_on_connect_flow(ap_client, coordinator, _mock_broadcast)

    assert "restarted" in mock_broadcast_calls
