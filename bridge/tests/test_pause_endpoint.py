"""Tests for POST /pause endpoint and pause flow helpers (Story 17.2)."""
from __future__ import annotations

import asyncio
from unittest.mock import AsyncMock, MagicMock, patch

import pytest
from aiohttp.test_utils import TestClient, TestServer

# Importing from bridge.bridge ensures the core/ sys.path bootstrap runs,
# making `import rest` available for direct helper imports below.
from bridge.bridge import (
    ArchipelagoClient,
    Config,
    MercurePublisher,
    StateManager,
    create_app,
)
import rest as _rest  # noqa: E402 - available after bridge.bridge import


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------

def _config(**overrides: object) -> Config:
    defaults: dict[str, object] = {
        "bridge_internal_token": "test-token",
        "ap_pid_file": "/tmp/test-ap.pid",
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
# POST /pause - auth
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_pause_no_auth_returns_401() -> None:
    app, _ = _make_app()
    async with TestClient(TestServer(app)) as client:
        resp = await client.post("/pause")
        assert resp.status == 401


@pytest.mark.asyncio
async def test_pause_wrong_token_returns_401() -> None:
    app, _ = _make_app()
    async with TestClient(TestServer(app)) as client:
        resp = await client.post("/pause", headers={"Authorization": "Bearer wrong"})
        assert resp.status == 401


@pytest.mark.asyncio
async def test_pause_empty_token_config_returns_401() -> None:
    """If BRIDGE_INTERNAL_TOKEN is not set, endpoint must reject all requests."""
    app, _ = _make_app(config=_config(bridge_internal_token=""))
    async with TestClient(TestServer(app)) as client:
        resp = await client.post("/pause", headers={"Authorization": "Bearer "})
        assert resp.status == 401


@pytest.mark.asyncio
async def test_pause_valid_token_returns_200() -> None:
    app, ap_client = _make_app()
    ap_client.ws_connected = False

    with patch.object(_rest, "_pause_flow", new=AsyncMock()):
        async with TestClient(TestServer(app)) as client:
            resp = await client.post("/pause", headers={"Authorization": "Bearer test-token"})
            assert resp.status == 200
            data = await resp.json()
            assert data["ok"] is True


# ---------------------------------------------------------------------------
# _poll_for_save
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_poll_for_save_returns_most_recent_apsave(tmp_path: object) -> None:
    import os

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

    # Patch asyncio.sleep and the deadline so it times out immediately
    original_get_event_loop = asyncio.get_event_loop

    class _FakeLoop:
        _t = 0.0

        def time(self) -> float:
            self._t += 100  # always past deadline
            return self._t

    with patch("rest.asyncio.get_event_loop", return_value=_FakeLoop()):
        result = await _rest._poll_for_save(ap_client)

    assert result is None


@pytest.mark.asyncio
async def test_poll_for_save_sends_save_command_when_connected(tmp_path: object) -> None:
    import os

    save_dir = str(tmp_path)
    save_file = os.path.join(save_dir, "game.apsave")

    _, ap_client = _make_app(config=_config(save_dir=save_dir))
    ap_client.ws_connected = True
    ap_client._ws = AsyncMock()
    ap_client._ws.send = AsyncMock()

    # Write the file only after the send_command is called
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
    import os

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
            import errno
            raise ProcessLookupError(errno.ESRCH, "No such process")

    with patch("rest._os.kill", side_effect=_fake_kill):
        with patch("rest.asyncio.sleep", new=AsyncMock()):
            await _rest._kill_ap(pid_file)

    import signal
    assert signal.SIGTERM in signals_sent


@pytest.mark.asyncio
async def test_kill_ap_missing_pid_file_logs_and_returns(tmp_path: object) -> None:
    pid_file = str(tmp_path / "missing.pid")
    # Should not raise
    await _rest._kill_ap(pid_file)


@pytest.mark.asyncio
async def test_kill_ap_already_gone_process(tmp_path: object) -> None:
    pid_file = str(tmp_path / "ap.pid")
    with open(pid_file, "w") as fh:
        fh.write("99999\n")

    def _raise(_pid: int, _sig: int) -> None:
        raise ProcessLookupError

    with patch("rest._os.kill", side_effect=_raise):
        await _rest._kill_ap(pid_file)  # must not raise


# ---------------------------------------------------------------------------
# _notify_paused
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_notify_paused_posts_to_symfony() -> None:
    _, ap_client = _make_app()

    mock_resp = AsyncMock()
    mock_resp.status = 200
    mock_resp.__aenter__ = AsyncMock(return_value=mock_resp)
    mock_resp.__aexit__ = AsyncMock(return_value=False)

    ap_client._http.post = MagicMock(return_value=mock_resp)

    await _rest._notify_paused(ap_client, save_key="sessions/run-1/saves/abc.apsave", failed_save=False)

    ap_client._http.post.assert_called_once()
    call_kwargs = ap_client._http.post.call_args
    assert "paused" in call_kwargs[0][0]
    assert call_kwargs[1]["json"]["saveKey"] == "sessions/run-1/saves/abc.apsave"
    assert call_kwargs[1]["json"]["failedSave"] is False


@pytest.mark.asyncio
async def test_notify_paused_failed_save_flag() -> None:
    _, ap_client = _make_app()

    mock_resp = AsyncMock()
    mock_resp.status = 200
    mock_resp.__aenter__ = AsyncMock(return_value=mock_resp)
    mock_resp.__aexit__ = AsyncMock(return_value=False)

    ap_client._http.post = MagicMock(return_value=mock_resp)

    await _rest._notify_paused(ap_client, save_key=None, failed_save=True)

    call_kwargs = ap_client._http.post.call_args
    assert call_kwargs[1]["json"]["failedSave"] is True
    assert call_kwargs[1]["json"]["saveKey"] is None


# ---------------------------------------------------------------------------
# _pause_flow integration (mocked deps)
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_pause_flow_failed_save_sets_flag(tmp_path: object) -> None:
    """When no .apsave is found, failed_save=True is sent to Symfony."""
    _, ap_client = _make_app(config=_config(save_dir=str(tmp_path)))
    ap_client.ws_connected = False

    notified: list[dict] = []

    async def _mock_notify(client: ArchipelagoClient, save_key: object, failed_save: bool) -> None:
        notified.append({"save_key": save_key, "failed_save": failed_save})

    with patch.object(_rest, "_poll_for_save", new=AsyncMock(return_value=None)):
        with patch.object(_rest, "_kill_ap", new=AsyncMock()):
            with patch.object(_rest, "_notify_paused", new=_mock_notify):
                await _rest._pause_flow(ap_client)

    assert len(notified) == 1
    assert notified[0]["failed_save"] is True
    assert notified[0]["save_key"] is None


@pytest.mark.asyncio
async def test_pause_flow_successful_save_uploads_and_kills(tmp_path: object) -> None:
    """When a save is found, MinIO upload is attempted and AP is killed."""
    import os

    save_file = str(tmp_path / "game.apsave")
    with open(save_file, "wb") as fh:
        fh.write(b"save")

    _, ap_client = _make_app(config=_config(save_dir=str(tmp_path)))
    ap_client.ws_connected = False

    kill_called: list[str] = []
    notified: list[dict] = []

    async def _mock_kill(pid_file: str) -> None:
        kill_called.append(pid_file)

    async def _mock_notify(client: ArchipelagoClient, save_key: object, failed_save: bool) -> None:
        notified.append({"save_key": save_key, "failed_save": failed_save})

    with patch.object(_rest, "_poll_for_save", new=AsyncMock(return_value=save_file)):
        with patch.object(_rest, "_upload_save", new=AsyncMock(return_value="sessions/run-1/saves/ts.apsave")):
            with patch.object(_rest, "_kill_ap", new=_mock_kill):
                with patch.object(_rest, "_notify_paused", new=_mock_notify):
                    await _rest._pause_flow(ap_client)

    assert len(kill_called) == 1
    assert len(notified) == 1
    assert notified[0]["failed_save"] is False
    assert notified[0]["save_key"] == "sessions/run-1/saves/ts.apsave"
