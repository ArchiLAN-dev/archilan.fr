"""Tests for the Operator API (apworlds, generation, server management)."""
from __future__ import annotations

import io
import zipfile
from pathlib import Path
from unittest.mock import AsyncMock, MagicMock, patch

import pytest
from httpx import ASGITransport, AsyncClient

from bridge.bridge import (
    ArchipelagoClient,
    Config,
    OperatorState,
    StateManager,
    create_app,
)
from bridge.core import rest_operator as _op


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _config(tmp_path: Path, **overrides: object) -> Config:
    defaults: dict[str, object] = {
        "internal_token": "test-token",
        "ap_worlds_dir": str(tmp_path / "worlds"),
        "ap_yamls_dir": str(tmp_path / "yamls"),
        "ap_output_dir": str(tmp_path / "output"),
        "ap_pid_file": str(tmp_path / "ap.pid"),
    }
    defaults.update(overrides)
    return Config(session_id="run-1", **defaults)  # type: ignore[arg-type]


def _make_app(tmp_path: Path, **cfg_overrides: object) -> tuple[object, OperatorState]:
    cfg = _config(tmp_path, **cfg_overrides)
    state = StateManager()
    ap_client = ArchipelagoClient(cfg, state, AsyncMock())
    operator_state = OperatorState(cfg)
    app = create_app(state, ap_client, operator_state=operator_state)
    return app, operator_state


def _mock_runtime(*, supports_generate: bool = False, supports_server: bool = False) -> AsyncMock:
    """Build a mock RuntimeAdapter with sync supports_* stubs."""
    m = AsyncMock()
    m.supports_generate = MagicMock(return_value=supports_generate)
    m.supports_server = MagicMock(return_value=supports_server)
    return m


HEADERS = {"Authorization": "Bearer test-token"}
BAD_HEADERS = {"Authorization": "Bearer wrong"}


def _make_apworld(path: Path, game: str = "Test Game", version: str = "1.0.0") -> bytes:
    """Create a minimal valid apworld zip and return its bytes."""
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as zf:
        zf.writestr(
            "worlds/test_game/__init__.py",
            f'game = "{game}"\n__version__ = "{version}"\n',
        )
    return buf.getvalue()


# ---------------------------------------------------------------------------
# Auth — all operator endpoints require INTERNAL_TOKEN
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_operator_auth_required(tmp_path: Path) -> None:
    app, _ = _make_app(tmp_path)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        assert (await client.get("/operator/apworlds")).status_code == 401
        assert (await client.get("/operator/server")).status_code == 401
        assert (await client.post("/operator/generate")).status_code == 401
        assert (await client.post("/operator/server/start")).status_code == 401
        assert (await client.post("/operator/server/stop")).status_code == 401


@pytest.mark.asyncio
async def test_operator_wrong_token_returns_401(tmp_path: Path) -> None:
    app, _ = _make_app(tmp_path)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/operator/apworlds", headers=BAD_HEADERS)
        assert resp.status_code == 401


# ---------------------------------------------------------------------------
# GET /operator/apworlds
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_get_apworlds_empty(tmp_path: Path) -> None:
    app, _ = _make_app(tmp_path)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/operator/apworlds", headers=HEADERS)
        assert resp.status_code == 200
        data = resp.json()
        assert data["apworlds"] == []


@pytest.mark.asyncio
async def test_get_apworlds_lists_installed_files(tmp_path: Path) -> None:
    worlds_dir = tmp_path / "worlds"
    worlds_dir.mkdir()
    apworld_bytes = _make_apworld(tmp_path, game="Hollow Knight", version="0.1.3")
    (worlds_dir / "hollow_knight.apworld").write_bytes(apworld_bytes)

    app, _ = _make_app(tmp_path)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/operator/apworlds", headers=HEADERS)
        data = resp.json()

    assert len(data["apworlds"]) == 1
    aw = data["apworlds"][0]
    assert aw["filename"] == "hollow_knight.apworld"
    assert aw["game"] == "Hollow Knight"
    assert aw["version"] == "0.1.3"


# ---------------------------------------------------------------------------
# POST /operator/apworlds
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_post_apworld_valid_returns_201(tmp_path: Path) -> None:
    apworld_bytes = _make_apworld(tmp_path, game="Test Game", version="2.0.0")

    app, _ = _make_app(tmp_path)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post(
            "/operator/apworlds",
            headers=HEADERS,
            files={"file": ("test_game.apworld", apworld_bytes, "application/octet-stream")},
        )
        assert resp.status_code == 201
        data = resp.json()
        assert data["filename"] == "test_game.apworld"
        assert data["game"] == "Test Game"
        assert data["version"] == "2.0.0"

    assert (tmp_path / "worlds" / "test_game.apworld").exists()


@pytest.mark.asyncio
async def test_post_apworld_invalid_zip_returns_400(tmp_path: Path) -> None:
    app, _ = _make_app(tmp_path)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post(
            "/operator/apworlds",
            headers=HEADERS,
            files={"file": ("bad.apworld", b"not a zip", "application/octet-stream")},
        )
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "invalid_apworld"


@pytest.mark.asyncio
async def test_post_apworld_duplicate_returns_409(tmp_path: Path) -> None:
    worlds_dir = tmp_path / "worlds"
    worlds_dir.mkdir()
    apworld_bytes = _make_apworld(tmp_path)
    (worlds_dir / "test_game.apworld").write_bytes(apworld_bytes)

    app, _ = _make_app(tmp_path)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post(
            "/operator/apworlds",
            headers=HEADERS,
            files={"file": ("test_game.apworld", apworld_bytes, "application/octet-stream")},
        )
        assert resp.status_code == 409
        data = resp.json()
        assert data["error"] == "already_exists"


# ---------------------------------------------------------------------------
# PUT /operator/apworlds/{filename}
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_put_apworld_overwrites_existing(tmp_path: Path) -> None:
    worlds_dir = tmp_path / "worlds"
    worlds_dir.mkdir()
    old_bytes = _make_apworld(tmp_path, game="Old Game", version="1.0.0")
    (worlds_dir / "test_game.apworld").write_bytes(old_bytes)

    new_bytes = _make_apworld(tmp_path, game="New Game", version="2.0.0")

    app, _ = _make_app(tmp_path)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.put(
            "/operator/apworlds/test_game.apworld",
            headers=HEADERS,
            files={"file": ("test_game.apworld", new_bytes, "application/octet-stream")},
        )
        assert resp.status_code == 200
        data = resp.json()
        assert data["game"] == "New Game"
        assert data["version"] == "2.0.0"


# ---------------------------------------------------------------------------
# DELETE /operator/apworlds/{filename}
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_delete_apworld_returns_204(tmp_path: Path) -> None:
    worlds_dir = tmp_path / "worlds"
    worlds_dir.mkdir()
    (worlds_dir / "test_game.apworld").write_bytes(_make_apworld(tmp_path))

    app, _ = _make_app(tmp_path)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.delete("/operator/apworlds/test_game.apworld", headers=HEADERS)
        assert resp.status_code == 204

    assert not (worlds_dir / "test_game.apworld").exists()


@pytest.mark.asyncio
async def test_delete_apworld_not_found_returns_404(tmp_path: Path) -> None:
    app, _ = _make_app(tmp_path)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.delete("/operator/apworlds/missing.apworld", headers=HEADERS)
        assert resp.status_code == 404


# ---------------------------------------------------------------------------
# POST /operator/generate
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_post_generate_not_configured_returns_503(tmp_path: Path) -> None:
    app, _ = _make_app(tmp_path)  # no ap_generate_cmd → supports_generate() == False
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post(
            "/operator/generate",
            headers=HEADERS,
            files=[("yamls", ("jean.yaml", b"name: Jean\ngame: HK", "text/plain"))],
        )
        assert resp.status_code == 503
        data = resp.json()
        assert data["error"] == "runtime_not_configured"


@pytest.mark.asyncio
async def test_post_generate_no_yamls_returns_422(tmp_path: Path) -> None:
    app, operator = _make_app(tmp_path)
    operator._runtime = _mock_runtime(supports_generate=True)

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post("/operator/generate", headers=HEADERS)
        assert resp.status_code == 422
        data = resp.json()
        assert data["error"] == "no_yamls"


@pytest.mark.asyncio
async def test_post_generate_starts_job_and_returns_202(tmp_path: Path) -> None:
    app, operator = _make_app(tmp_path)
    operator._runtime = _mock_runtime(supports_generate=True)

    with patch.object(_op, "_run_generation", new=AsyncMock()):
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            resp = await client.post(
                "/operator/generate",
                headers=HEADERS,
                files=[("yamls", ("jean.yaml", b"name: Jean\ngame: HK", "text/plain"))],
            )
            assert resp.status_code == 202
            data = resp.json()
            assert data["jobId"].startswith("gen-")

    assert operator.current_job is not None
    assert operator.current_job.status == "pending"


@pytest.mark.asyncio
async def test_post_generate_job_already_running_returns_409(tmp_path: Path) -> None:
    app, operator = _make_app(tmp_path)
    operator._runtime = _mock_runtime(supports_generate=True)
    operator._current_job_id = "gen-existing"
    operator._jobs["gen-existing"] = _op.GenerationJob(job_id="gen-existing", status="running")

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post(
            "/operator/generate",
            headers=HEADERS,
            files=[("yamls", ("jean.yaml", b"name: Jean", "text/plain"))],
        )
        assert resp.status_code == 409
        assert resp.json()["error"] == "job_already_running"


# ---------------------------------------------------------------------------
# GET /operator/jobs/{jobId}
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_get_job_not_found_returns_404(tmp_path: Path) -> None:
    app, _ = _make_app(tmp_path)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/operator/jobs/gen-unknown", headers=HEADERS)
        assert resp.status_code == 404


@pytest.mark.asyncio
async def test_get_job_returns_job_state(tmp_path: Path) -> None:
    app, operator = _make_app(tmp_path)
    job = _op.GenerationJob(job_id="gen-abc123", status="running", progress="Filling...")
    operator._jobs["gen-abc123"] = job
    operator._current_job_id = "gen-abc123"

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/operator/jobs/gen-abc123", headers=HEADERS)
        assert resp.status_code == 200
        data = resp.json()
        assert data["jobId"] == "gen-abc123"
        assert data["status"] == "running"
        assert data["progress"] == "Filling..."


# ---------------------------------------------------------------------------
# GET /operator/server
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_get_server_not_running(tmp_path: Path) -> None:
    app, _ = _make_app(tmp_path)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.get("/operator/server", headers=HEADERS)
        assert resp.status_code == 200
        data = resp.json()
        assert data["running"] is False
        assert data["handle"] is None
        assert data["pid"] is None


# ---------------------------------------------------------------------------
# POST /operator/server/start
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_server_start_not_configured_returns_503(tmp_path: Path) -> None:
    app, _ = _make_app(tmp_path)  # no ap_start_cmd → supports_server() == False
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post(
            "/operator/server/start",
            headers=HEADERS,
            json={"seedFile": "AP_12345678.archipelago"},
        )
        assert resp.status_code == 503
        assert resp.json()["error"] == "runtime_not_configured"


@pytest.mark.asyncio
async def test_server_start_already_running_returns_409(tmp_path: Path) -> None:
    app, operator = _make_app(tmp_path)
    operator.server.running = True

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post(
            "/operator/server/start",
            headers=HEADERS,
            json={"seedFile": "AP_12345678.archipelago"},
        )
        assert resp.status_code == 409
        assert resp.json()["error"] == "already_running"


@pytest.mark.asyncio
async def test_server_start_seed_not_found_returns_404(tmp_path: Path) -> None:
    app, operator = _make_app(tmp_path)
    operator._runtime = _mock_runtime(supports_server=True)

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post(
            "/operator/server/start",
            headers=HEADERS,
            json={"seedFile": "AP_99999999.archipelago"},
        )
        assert resp.status_code == 404
        assert resp.json()["error"] == "seed_not_found"


@pytest.mark.asyncio
async def test_server_start_health_timeout_returns_504(tmp_path: Path) -> None:
    output_dir = tmp_path / "output"
    output_dir.mkdir()
    (output_dir / "AP_12345678.archipelago").write_bytes(b"seed")

    app, operator = _make_app(tmp_path)
    mock_rt = _mock_runtime(supports_server=True)
    mock_rt.start_server.return_value = "test-handle"
    operator._runtime = mock_rt

    with patch.object(_op, "_tcp_probe", new=AsyncMock(return_value=False)):
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            resp = await client.post(
                "/operator/server/start",
                headers=HEADERS,
                json={"seedFile": "AP_12345678.archipelago"},
            )
            assert resp.status_code == 504
            assert resp.json()["error"] == "server_health_timeout"

    mock_rt.stop_server.assert_called_once_with("test-handle")


@pytest.mark.asyncio
async def test_server_start_success(tmp_path: Path) -> None:
    output_dir = tmp_path / "output"
    output_dir.mkdir()
    (output_dir / "AP_12345678.archipelago").write_bytes(b"seed")

    app, operator = _make_app(tmp_path, ap_ws_url="ws://localhost:38281")
    mock_rt = _mock_runtime(supports_server=True)
    mock_rt.start_server.return_value = "1234"
    operator._runtime = mock_rt

    with patch.object(_op, "_tcp_probe", new=AsyncMock(return_value=True)):
        async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
            resp = await client.post(
                "/operator/server/start",
                headers=HEADERS,
                json={"seedFile": "AP_12345678.archipelago"},
            )
            assert resp.status_code == 200
            data = resp.json()
            assert data["ok"] is True
            assert data["handle"] == "1234"
            assert data["pid"] == 1234  # derived from handle "1234"
            assert data["port"] == 38281

    assert operator.server.running is True
    assert operator.server.handle == "1234"
    assert operator.server.seed_file == "AP_12345678.archipelago"


# ---------------------------------------------------------------------------
# POST /operator/server/stop
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_server_stop_not_running_returns_409(tmp_path: Path) -> None:
    app, _ = _make_app(tmp_path)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post("/operator/server/stop", headers=HEADERS)
        assert resp.status_code == 409
        assert resp.json()["error"] == "not_running"


@pytest.mark.asyncio
async def test_server_stop_clears_running_state(tmp_path: Path) -> None:
    app, operator = _make_app(tmp_path)
    mock_rt = _mock_runtime()
    operator._runtime = mock_rt

    operator.server.running = True
    operator.server.handle = "99999"
    operator.server.seed_file = "AP_12345678.archipelago"

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        resp = await client.post("/operator/server/stop", headers=HEADERS)
        assert resp.status_code == 200
        assert resp.json()["ok"] is True

    mock_rt.stop_server.assert_called_once_with("99999")
    assert operator.server.running is False
    assert operator.server.handle is None
    assert operator.server.pid is None  # derived property: None when handle is None


# ---------------------------------------------------------------------------
# Unit — _parse_apworld_meta
# ---------------------------------------------------------------------------

def test_parse_apworld_meta_reads_init_py(tmp_path: Path) -> None:
    path = str(tmp_path / "test.apworld")
    with zipfile.ZipFile(path, "w") as zf:
        zf.writestr("worlds/hollow_knight/__init__.py", 'game = "Hollow Knight"\n__version__ = "0.1.3"\n')

    game, version = _op._parse_apworld_meta(path)
    assert game == "Hollow Knight"
    assert version == "0.1.3"


def test_parse_apworld_meta_reads_manifest_json(tmp_path: Path) -> None:
    path = str(tmp_path / "test.apworld")
    with zipfile.ZipFile(path, "w") as zf:
        zf.writestr("manifest.json", '{"game": "A Link to the Past", "version": "5.0.0"}')

    game, version = _op._parse_apworld_meta(path)
    assert game == "A Link to the Past"
    assert version == "5.0.0"


def test_parse_apworld_meta_returns_none_for_invalid_zip(tmp_path: Path) -> None:
    path = str(tmp_path / "bad.apworld")
    Path(path).write_bytes(b"not a zip")

    game, version = _op._parse_apworld_meta(path)
    assert game is None
    assert version is None


# ---------------------------------------------------------------------------
# Unit — _run_generation (via SubprocessRuntimeAdapter under the hood)
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_run_generation_done(tmp_path: Path) -> None:
    output_dir = tmp_path / "output"
    output_dir.mkdir()

    cfg = _config(tmp_path, ap_generate_cmd="echo done", ap_output_dir=str(output_dir))
    operator = OperatorState(cfg)
    broadcast_calls: list[str] = []

    async def _broadcast(event_type: str, payload: dict) -> None:  # type: ignore[type-arg]
        broadcast_calls.append(payload.get("type", event_type))

    operator.broadcast = _broadcast
    job = operator.new_job(race_mode=False)

    async def _fake_subprocess(*args: object, **kwargs: object) -> MagicMock:
        proc = MagicMock()
        proc.returncode = 0

        async def _lines():  # type: ignore[return]
            yield b"Generating...\n"
            yield b"Done.\n"

        proc.stdout = _lines()

        async def wait() -> int:
            return 0

        proc.wait = wait

        seed_path = output_dir / "AP_12345678.archipelago"
        seed_path.write_bytes(b"seed")
        return proc

    with patch("asyncio.create_subprocess_shell", new=_fake_subprocess):
        await _op._run_generation(job, operator)

    assert job.status == "done"
    assert job.seed_file == "AP_12345678.archipelago"
    assert job.seed == 12345678
    assert "generation_done" in broadcast_calls


@pytest.mark.asyncio
async def test_run_generation_failed_nonzero_exit(tmp_path: Path) -> None:
    cfg = _config(tmp_path, ap_generate_cmd="false")
    operator = OperatorState(cfg)
    broadcast_calls: list[str] = []

    async def _broadcast(event_type: str, payload: dict) -> None:  # type: ignore[type-arg]
        broadcast_calls.append(payload.get("type", event_type))

    operator.broadcast = _broadcast
    job = operator.new_job(race_mode=False)

    async def _fake_subprocess(*args: object, **kwargs: object) -> MagicMock:
        proc = MagicMock()
        proc.returncode = 1

        async def _lines():  # type: ignore[return]
            yield b"Error: yaml parse failed\n"

        proc.stdout = _lines()

        async def wait() -> int:
            return 1

        proc.wait = wait
        return proc

    with patch("asyncio.create_subprocess_shell", new=_fake_subprocess):
        await _op._run_generation(job, operator)

    assert job.status == "failed"
    assert job.error == "generation_failed"
    assert "generation_failed" in broadcast_calls
