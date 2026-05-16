"""Tests for POST /resume endpoint and resume flow helpers (Story 17.3)."""
from __future__ import annotations

import os
import time
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
from bridge.core import rest_session as _rest
from bridge.core.coordinator import PauseResumeCoordinator


# ---------------------------------------------------------------------------
# Fixtures
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


def _make_app(config: Config | None = None) -> tuple[object, ArchipelagoClient]:
    cfg = config or _config()
    state = StateManager()
    publisher = MagicMock(spec=MercurePublisher)
    publisher.publish = AsyncMock()
    ap_client = ArchipelagoClient(cfg, state, publisher)
    ap_client._http = AsyncMock()
    app = create_app(state, ap_client)
    return app, ap_client


# ---------------------------------------------------------------------------
# POST /resume - auth
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_resume_no_auth_returns_401() -> None:
    app, _ = _make_app()
    async with TestClient(TestServer(app)) as client:
        resp = await client.post("/resume")
        assert resp.status == 401


@pytest.mark.asyncio
async def test_resume_wrong_token_returns_401() -> None:
    app, _ = _make_app()
    async with TestClient(TestServer(app)) as client:
        resp = await client.post("/resume", headers={"Authorization": "Bearer bad"})
        assert resp.status == 401


@pytest.mark.asyncio
async def test_resume_valid_token_returns_200() -> None:
    app, _ = _make_app()

    with patch.object(_rest, "_resume_flow", new=AsyncMock()):
        async with TestClient(TestServer(app)) as client:
            resp = await client.post(
                "/resume",
                json={"lastSaveKey": "sessions/run-1/saves/ts.apsave"},
                headers={"Authorization": "Bearer test-token"},
            )
            assert resp.status == 200
            data = await resp.json()
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
# _notify_restarted
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_notify_restarted_posts_empty_connection_details() -> None:
    _, ap_client = _make_app()

    mock_resp = AsyncMock()
    mock_resp.status = 200
    mock_resp.__aenter__ = AsyncMock(return_value=mock_resp)
    mock_resp.__aexit__ = AsyncMock(return_value=False)
    ap_client._http.post = MagicMock(return_value=mock_resp)

    await _rest._notify_restarted(ap_client)

    ap_client._http.post.assert_called_once()
    call_kwargs = ap_client._http.post.call_args
    body = call_kwargs[1]["json"]
    assert body["connectionHost"] == ""
    assert body["connectionPort"] == 0
    assert body["bridgePort"] == 0
    assert "restarted" in call_kwargs[0][0]


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
    notified: list[bool] = []

    async def _mock_launch(client: ArchipelagoClient, save_path: str) -> MagicMock:
        launched.append(save_path)
        proc = MagicMock()
        proc.returncode = None
        proc.kill = MagicMock()
        return proc

    async def _mock_health(client: ArchipelagoClient, timeout: float = 60.0) -> bool:
        return True

    async def _mock_notify(client: ArchipelagoClient) -> None:
        notified.append(True)

    with patch.object(_rest, "_launch_ap", new=_mock_launch):
        with patch.object(_rest, "_wait_for_ap_health", new=_mock_health):
            with patch.object(_rest, "_notify_restarted", new=_mock_notify):
                await _rest._resume_flow(ap_client, last_save_key=None, coordinator=PauseResumeCoordinator())

    assert launched == [save_file]
    assert notified == [True]


@pytest.mark.asyncio
async def test_resume_flow_falls_back_to_minio_when_no_local_save(tmp_path: object) -> None:
    _, ap_client = _make_app(config=_config(save_dir=str(tmp_path)))

    save_key = "sessions/run-1/saves/ts.apsave"
    downloaded: list[str] = []
    launched: list[str] = []

    async def _mock_download(client: ArchipelagoClient, key: str) -> str:
        downloaded.append(key)
        path = str(tmp_path / "downloaded.apsave")
        with open(path, "wb") as f:
            f.write(b"save")
        return path

    async def _mock_launch(client: ArchipelagoClient, save_path: str) -> MagicMock:
        launched.append(save_path)
        proc = MagicMock()
        proc.returncode = None
        return proc

    with patch.object(_rest, "_download_save_from_minio", new=_mock_download):
        with patch.object(_rest, "_launch_ap", new=_mock_launch):
            with patch.object(_rest, "_wait_for_ap_health", new=AsyncMock(return_value=True)):
                with patch.object(_rest, "_notify_restarted", new=AsyncMock()):
                    await _rest._resume_flow(ap_client, last_save_key=save_key, coordinator=PauseResumeCoordinator())

    assert downloaded == [save_key]
    assert len(launched) == 1


@pytest.mark.asyncio
async def test_resume_flow_stops_if_no_save_available(tmp_path: object) -> None:
    _, ap_client = _make_app(config=_config(save_dir=str(tmp_path)))

    launch_called = False

    async def _mock_launch(client: ArchipelagoClient, save_path: str) -> None:
        nonlocal launch_called
        launch_called = True

    with patch.object(_rest, "_launch_ap", new=_mock_launch):
        with patch.object(_rest, "_notify_restart_failed", new=AsyncMock()) as mock_notify_failed:
            await _rest._resume_flow(ap_client, last_save_key=None, coordinator=PauseResumeCoordinator())
            mock_notify_failed.assert_awaited_once()

    assert not launch_called


@pytest.mark.asyncio
async def test_resume_flow_kills_ap_on_health_check_failure(tmp_path: object) -> None:
    save_file = str(tmp_path / "game.apsave")
    with open(save_file, "wb") as f:
        f.write(b"data")

    _, ap_client = _make_app(config=_config(save_dir=str(tmp_path)))

    killed = False
    mock_proc = MagicMock()
    mock_proc.returncode = None

    def _kill() -> None:
        nonlocal killed
        killed = True

    mock_proc.kill = _kill

    with patch.object(_rest, "_launch_ap", new=AsyncMock(return_value=mock_proc)):
        with patch.object(_rest, "_wait_for_ap_health", new=AsyncMock(return_value=False)):
            with patch.object(_rest, "_notify_restarted", new=AsyncMock()) as mock_notify:
                with patch.object(_rest, "_notify_restart_failed", new=AsyncMock()) as mock_notify_failed:
                    await _rest._resume_flow(ap_client, last_save_key=None, coordinator=PauseResumeCoordinator())
                    mock_notify.assert_not_called()
                    mock_notify_failed.assert_awaited_once()

    assert killed is True
