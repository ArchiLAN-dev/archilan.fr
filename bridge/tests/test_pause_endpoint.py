"""Tests for POST /pause endpoint and pause flow helpers."""
from __future__ import annotations

import errno
import os
import signal
from unittest.mock import AsyncMock, patch

import pytest
from httpx import ASGITransport, AsyncClient

from bridge.bridge import (
    ArchipelagoClient,
    Config,
    StateManager,
    create_app,
)
from bridge.core import rest_session as _rest
from bridge.core.coordinator import PauseResumeCoordinator


# ---------------------------------------------------------------------------
# Fixtures
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


def _make_app(config: Config | None = None) -> tuple[object, ArchipelagoClient]:
    cfg = config or _config()
    state = StateManager()
    broadcast = AsyncMock()
    ap_client = ArchipelagoClient(cfg, state, broadcast)
    app = create_app(state, ap_client)
    return app, ap_client


# ---------------------------------------------------------------------------
# POST /pause - auth
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_pause_no_auth_returns_401() -> None:
    app, _ = _make_app()
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post("/pause")
        assert resp.status_code == 401


@pytest.mark.asyncio
async def test_pause_wrong_token_returns_401() -> None:
    app, _ = _make_app()
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post("/pause", headers={"Authorization": "Bearer wrong"})
        assert resp.status_code == 401


@pytest.mark.asyncio
async def test_pause_empty_token_config_returns_401() -> None:
    app, _ = _make_app(config=_config(internal_token=""))
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post("/pause", headers={"Authorization": "Bearer "})
        assert resp.status_code == 401


@pytest.mark.asyncio
async def test_pause_valid_token_returns_200() -> None:
    app, ap_client = _make_app()
    ap_client.ws_connected = False

    with patch.object(_rest, "_pause_flow", new=AsyncMock()):
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            resp = await client.post("/pause", headers={"Authorization": "Bearer test-token"})
            assert resp.status_code == 200
            data = resp.json()
            assert data["ok"] is True


# ---------------------------------------------------------------------------
# _poll_for_save
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_poll_for_save_returns_most_recent_apsave(tmp_path: object) -> None:
    save_dir = str(tmp_path)
    save_file = os.path.join(save_dir, "game.apsave")
    with open(save_file, "wb") as fh:
        fh.write(b"data")

    _, ap_client = _make_app(config=_config(save_dir=save_dir))
    ap_client.ws_connected = False

    result = await _rest._poll_for_save(ap_client)
    assert result == save_file


@pytest.mark.asyncio
async def test_poll_for_save_returns_none_when_timeout(tmp_path: object) -> None:
    _, ap_client = _make_app(config=_config(save_dir=str(tmp_path)))
    ap_client.ws_connected = False

    class _FakeLoop:
        _t = 0.0

        def time(self) -> float:
            self._t += 100
            return self._t

    with patch("bridge.core.rest_session.asyncio.get_event_loop", return_value=_FakeLoop()):
        result = await _rest._poll_for_save(ap_client)

    assert result is None


@pytest.mark.asyncio
async def test_poll_for_save_sends_save_command_when_connected(tmp_path: object) -> None:
    save_dir = str(tmp_path)
    save_file = os.path.join(save_dir, "game.apsave")

    _, ap_client = _make_app(config=_config(save_dir=save_dir))
    ap_client.ws_connected = True
    ap_client._ws = AsyncMock()
    ap_client._ws.send = AsyncMock()

    original_send = ap_client.send_command

    async def _write_then_send(cmd: str) -> None:
        with open(save_file, "wb") as fh:
            fh.write(b"save-data")
        await original_send(cmd)

    ap_client.send_command = _write_then_send

    result = await _rest._poll_for_save(ap_client)
    assert result == save_file


# ---------------------------------------------------------------------------
# _kill_ap
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_kill_ap_sends_sigterm_then_waits(tmp_path: object) -> None:
    pid_file = str(tmp_path / "ap.pid")
    with open(pid_file, "w") as fh:
        fh.write("12345\n")

    signals_sent: list[int] = []
    kill_count = 0

    def _fake_kill(pid: int, sig: int) -> None:
        nonlocal kill_count
        signals_sent.append(sig)
        kill_count += 1
        if kill_count > 1:
            raise ProcessLookupError(errno.ESRCH, "No such process")

    with patch("bridge.core.rest_session._os.kill", side_effect=_fake_kill):
        with patch("bridge.core.rest_session.asyncio.sleep", new=AsyncMock()):
            await _rest._kill_ap(pid_file)

    assert signal.SIGTERM in signals_sent


@pytest.mark.asyncio
async def test_kill_ap_missing_pid_file_logs_and_returns(tmp_path: object) -> None:
    pid_file = str(tmp_path / "missing.pid")
    await _rest._kill_ap(pid_file)


@pytest.mark.asyncio
async def test_kill_ap_already_gone_process(tmp_path: object) -> None:
    pid_file = str(tmp_path / "ap.pid")
    with open(pid_file, "w") as fh:
        fh.write("99999\n")

    def _raise(_pid: int, _sig: int) -> None:
        raise ProcessLookupError

    with patch("bridge.core.rest_session._os.kill", side_effect=_raise):
        await _rest._kill_ap(pid_file)


# ---------------------------------------------------------------------------
# _pause_flow integration (mocked deps)
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_pause_flow_failed_save_broadcasts_lifecycle(tmp_path: object) -> None:
    """When no .apsave is found, lifecycle paused event is broadcast with failedSave=True."""
    _, ap_client = _make_app(config=_config(save_dir=str(tmp_path)))
    ap_client.ws_connected = False

    broadcasts: list[dict] = []

    async def _mock_broadcast(event_type: str, payload: dict) -> None:
        broadcasts.append({"type": event_type, **payload})

    with patch.object(_rest, "_poll_for_save", new=AsyncMock(return_value=None)):
        with patch.object(_rest, "_kill_ap", new=AsyncMock()):
            await _rest._pause_flow(ap_client, PauseResumeCoordinator(), _mock_broadcast)

    assert len(broadcasts) == 1
    b = broadcasts[0]
    assert b["type"] == "lifecycle"
    assert b["event"] == "paused"
    assert b["failedSave"] is True


@pytest.mark.asyncio
async def test_pause_flow_successful_save_kills_ap(tmp_path: object) -> None:
    """When a save is found, AP is killed and paused event is broadcast without saveKey."""
    save_file = str(tmp_path / "game.apsave")
    with open(save_file, "wb") as fh:
        fh.write(b"save")

    _, ap_client = _make_app(config=_config(save_dir=str(tmp_path)))
    ap_client.ws_connected = False

    kill_called: list[str] = []
    broadcasts: list[dict] = []

    async def _mock_kill(pid_file: str) -> None:
        kill_called.append(pid_file)

    async def _mock_broadcast(event_type: str, payload: dict) -> None:
        broadcasts.append({"type": event_type, **payload})

    with patch.object(_rest, "_poll_for_save", new=AsyncMock(return_value=save_file)):
        with patch.object(_rest, "_kill_ap", new=_mock_kill):
            await _rest._pause_flow(ap_client, PauseResumeCoordinator(), _mock_broadcast)

    assert len(kill_called) == 1
    assert len(broadcasts) == 1
    assert broadcasts[0]["event"] == "paused"
    assert broadcasts[0]["failedSave"] is False
    assert "saveKey" not in broadcasts[0]
