"""Tests for POST /resume endpoint and resume flow helpers."""
from __future__ import annotations

import os
import time
from unittest.mock import AsyncMock, MagicMock, patch

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
        "ap_launch_cmd": "python multiserver.py",
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
# POST /resume - auth
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_resume_no_auth_returns_401() -> None:
    app, _ = _make_app()
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post("/resume")
        assert resp.status_code == 401


@pytest.mark.asyncio
async def test_resume_wrong_token_returns_401() -> None:
    app, _ = _make_app()
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post("/resume", headers={"Authorization": "Bearer bad"})
        assert resp.status_code == 401


@pytest.mark.asyncio
async def test_resume_valid_token_returns_200() -> None:
    app, _ = _make_app()

    with patch.object(_rest, "_resume_flow", new=AsyncMock()):
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            resp = await client.post(
                "/resume",
                headers={"Authorization": "Bearer test-token"},
            )
            assert resp.status_code == 200
            data = resp.json()
            assert data["ok"] is True


# ---------------------------------------------------------------------------
# _find_local_save
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_find_local_save_returns_most_recent(tmp_path: object) -> None:
    save1 = os.path.join(str(tmp_path), "old.apsave")
    save2 = os.path.join(str(tmp_path), "new.apsave")
    with open(save1, "w") as f:
        f.write("old")
    time.sleep(0.01)
    with open(save2, "w") as f:
        f.write("new")

    result = await _rest._find_local_save(str(tmp_path))
    assert result is not None
    assert os.path.basename(result) == "new.apsave"


@pytest.mark.asyncio
async def test_find_local_save_returns_none_when_empty(tmp_path: object) -> None:
    result = await _rest._find_local_save(str(tmp_path))
    assert result is None


# ---------------------------------------------------------------------------
# _wait_for_ap_health
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_wait_for_ap_health_returns_true_on_connect() -> None:
    _, ap_client = _make_app()

    mock_reader = AsyncMock()
    mock_writer = MagicMock()
    mock_writer.close = MagicMock()
    mock_writer.wait_closed = AsyncMock()

    with patch("bridge.core.rest_session.asyncio.open_connection", return_value=(mock_reader, mock_writer)):
        result = await _rest._wait_for_ap_health(ap_client, timeout=10.0)

    assert result is True


@pytest.mark.asyncio
async def test_wait_for_ap_health_returns_false_on_timeout() -> None:
    _, ap_client = _make_app()

    class _FakeLoop:
        _t = 0.0
        def time(self) -> float:
            self._t += 200
            return self._t

    with patch("bridge.core.rest_session.asyncio.get_event_loop", return_value=_FakeLoop()):
        with patch("bridge.core.rest_session.asyncio.open_connection", side_effect=OSError):
            result = await _rest._wait_for_ap_health(ap_client, timeout=60.0)

    assert result is False


# ---------------------------------------------------------------------------
# _resume_flow integration (mocked deps)
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_resume_flow_uses_local_save_if_available(tmp_path: object) -> None:
    save_file = str(tmp_path / "game.apsave")
    with open(save_file, "wb") as f:
        f.write(b"data")

    _, ap_client = _make_app(config=_config(save_dir=str(tmp_path)))

    launched: list[str] = []
    broadcasts: list[str] = []

    async def _mock_launch(client: ArchipelagoClient, save_path: str) -> MagicMock:
        launched.append(save_path)
        proc = MagicMock()
        proc.returncode = None
        proc.kill = MagicMock()
        return proc

    async def _mock_broadcast(event_type: str, payload: dict) -> None:
        broadcasts.append(payload.get("event", ""))

    with patch.object(_rest, "_launch_ap", new=_mock_launch):
        with patch.object(_rest, "_wait_for_ap_health", new=AsyncMock(return_value=True)):
            await _rest._resume_flow(ap_client, coordinator=PauseResumeCoordinator(), broadcast=_mock_broadcast)

    assert launched == [save_file]
    assert "restarted" in broadcasts


@pytest.mark.asyncio
async def test_resume_flow_stops_if_no_save_available(tmp_path: object) -> None:
    _, ap_client = _make_app(config=_config(save_dir=str(tmp_path)))

    launch_called = False
    broadcasts: list[str] = []

    async def _mock_launch(client: ArchipelagoClient, save_path: str) -> None:
        nonlocal launch_called
        launch_called = True

    async def _mock_broadcast(event_type: str, payload: dict) -> None:
        broadcasts.append(payload.get("event", ""))

    with patch.object(_rest, "_launch_ap", new=_mock_launch):
        await _rest._resume_flow(ap_client, coordinator=PauseResumeCoordinator(), broadcast=_mock_broadcast)

    assert not launch_called
    assert "restart_failed" in broadcasts


@pytest.mark.asyncio
async def test_resume_flow_kills_ap_on_health_check_failure(tmp_path: object) -> None:
    save_file = str(tmp_path / "game.apsave")
    with open(save_file, "wb") as f:
        f.write(b"data")

    _, ap_client = _make_app(config=_config(save_dir=str(tmp_path)))

    killed = False
    broadcasts: list[str] = []
    mock_proc = MagicMock()
    mock_proc.returncode = None

    def _kill() -> None:
        nonlocal killed
        killed = True

    mock_proc.kill = _kill

    async def _mock_broadcast(event_type: str, payload: dict) -> None:
        broadcasts.append(payload.get("event", ""))

    with patch.object(_rest, "_launch_ap", new=AsyncMock(return_value=mock_proc)):
        with patch.object(_rest, "_wait_for_ap_health", new=AsyncMock(return_value=False)):
            await _rest._resume_flow(ap_client, coordinator=PauseResumeCoordinator(), broadcast=_mock_broadcast)

    assert "restart_failed" in broadcasts
    assert killed is True
