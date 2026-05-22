from __future__ import annotations

from unittest.mock import MagicMock, patch

from fastapi.testclient import TestClient

from app.port_pool import PortPool
from app.server_lifecycle import (
    _health_check_loop,
    launch_server,
    restart_server,
    stop_server,
)
from app.session_store import SessionStore

API_KEY = "test-secret"
HEADERS = {"X-Api-Key": API_KEY}


def _make_container(container_id: str = "abc123") -> MagicMock:
    c = MagicMock()
    c.id = container_id
    return c


def _running_store(session_id: str, *, output_file: str = "/workspace/sess/output/world.archipelago") -> SessionStore:
    store = SessionStore()
    store.create(session_id)
    store.update(session_id, status="generated", outputFile=output_file)
    return store


# ─── launch_server ───────────────────────────────────────────────────────────

async def test_launch_returns_host_port_password() -> None:
    store = _running_store("sess-1")
    pool = PortPool(9000, 9001)
    mock_dm = MagicMock()
    mock_dm.run_container.return_value = _make_container("ctr-1")

    with patch("app.server_lifecycle.asyncio.create_task"):
        result = await launch_server("sess-1", store, pool, mock_dm, image="ap:test")

    assert "error" not in result
    assert result["containerHost"] == "0.0.0.0"
    assert result["containerPort"] == 9000
    assert result["serverPassword"]


async def test_launch_sets_session_status_running() -> None:
    store = _running_store("sess-1")
    pool = PortPool(9000, 9001)
    mock_dm = MagicMock()
    mock_dm.run_container.return_value = _make_container("ctr-1")

    with patch("app.server_lifecycle.asyncio.create_task"):
        await launch_server("sess-1", store, pool, mock_dm, image="ap:test")

    session = store.get("sess-1")
    assert session["status"] == "running"
    assert session["containerPort"] == 9000
    assert session["containerId"] == "ctr-1"


async def test_launch_unknown_session_returns_not_found() -> None:
    result = await launch_server("ghost", SessionStore(), PortPool(9000, 9001), MagicMock(), image="ap:test")
    assert result["error"] == "not_found"


async def test_launch_already_running_returns_conflict() -> None:
    store = SessionStore()
    store.create("sess-1")
    store.update("sess-1", status="running")
    result = await launch_server("sess-1", store, PortPool(9000, 9001), MagicMock(), image="ap:test")
    assert result["error"] == "already_running"


async def test_launch_not_ready_returns_error() -> None:
    store = SessionStore()
    store.create("sess-1")  # status="generating", no outputFile
    result = await launch_server("sess-1", store, PortPool(9000, 9001), MagicMock(), image="ap:test")
    assert result["error"] == "not_ready"


async def test_launch_no_ports_available_returns_error() -> None:
    store = _running_store("sess-1")
    pool = PortPool(9000, 9000)
    pool.allocate()  # exhaust the pool
    result = await launch_server("sess-1", store, pool, MagicMock(), image="ap:test")
    assert result["error"] == "no_ports_available"


async def test_launch_docker_failure_releases_port() -> None:
    store = _running_store("sess-1")
    pool = PortPool(9000, 9001)
    mock_dm = MagicMock()
    mock_dm.run_container.side_effect = RuntimeError("daemon unreachable")

    result = await launch_server("sess-1", store, pool, mock_dm, image="ap:test")

    assert result["error"] == "container_start_failed"
    assert pool.available == 2  # port was returned


# ─── stop_server ─────────────────────────────────────────────────────────────

async def test_stop_sets_status_stopped() -> None:
    store = SessionStore()
    store.create("sess-1")
    store.update("sess-1", status="running", containerPort=9000, containerId="ctr-1", serverPassword="pw")

    pool = PortPool(9000, 9001)
    pool.allocate()

    result = await stop_server("sess-1", store, pool, MagicMock())

    assert result["status"] == "stopped"
    assert store.get("sess-1")["status"] == "stopped"
    assert store.get("sess-1")["containerPort"] is None


async def test_stop_releases_port() -> None:
    store = SessionStore()
    store.create("sess-1")
    store.update("sess-1", status="running", containerPort=9000, containerId="ctr-1")

    pool = PortPool(9000, 9001)
    pool.allocate()  # take 9000

    await stop_server("sess-1", store, pool, MagicMock())

    assert pool.available == 2


async def test_stop_calls_docker_stop_container() -> None:
    store = SessionStore()
    store.create("sess-1")
    store.update("sess-1", status="running", containerPort=9000, containerId="ctr-xyz")

    mock_dm = MagicMock()
    await stop_server("sess-1", store, PortPool(9000, 9001), mock_dm)

    mock_dm.stop_container.assert_called_once_with("ctr-xyz")


async def test_stop_unknown_session_returns_not_found() -> None:
    result = await stop_server("ghost", SessionStore(), PortPool(9000, 9001), MagicMock())
    assert result["error"] == "not_found"


# ─── restart_server ───────────────────────────────────────────────────────────

async def test_restart_stops_old_container_and_relaunches() -> None:
    store = SessionStore()
    store.create("sess-1")
    store.update(
        "sess-1",
        status="crashed",
        containerPort=9000,
        containerId="old-ctr",
        outputFile="/workspace/sess-1/output/world.archipelago",
    )

    pool = PortPool(9000, 9010)
    pool.allocate()  # simulate 9000 in use

    mock_dm = MagicMock()
    mock_dm.run_container.return_value = _make_container("new-ctr")

    with patch("app.server_lifecycle.asyncio.create_task"):
        result = await restart_server("sess-1", store, pool, mock_dm, image="ap:test")

    mock_dm.stop_container.assert_called_once_with("old-ctr")
    assert "error" not in result
    assert result["containerPort"] is not None
    assert result["serverPassword"] is not None


async def test_restart_session_ends_as_running() -> None:
    store = SessionStore()
    store.create("sess-1")
    store.update(
        "sess-1",
        status="crashed",
        containerPort=9000,
        containerId="old-ctr",
        outputFile="/workspace/sess-1/output/world.archipelago",
    )

    pool = PortPool(9000, 9010)
    pool.allocate()

    mock_dm = MagicMock()
    mock_dm.run_container.return_value = _make_container("new-ctr")

    with patch("app.server_lifecycle.asyncio.create_task"):
        await restart_server("sess-1", store, pool, mock_dm, image="ap:test")

    assert store.get("sess-1")["status"] == "running"
    assert store.get("sess-1")["containerPort"] is not None


async def test_restart_unknown_session_returns_not_found() -> None:
    result = await restart_server("ghost", SessionStore(), PortPool(9000, 9001), MagicMock(), image="ap:test")
    assert result["error"] == "not_found"


async def test_restart_rejects_session_that_is_not_crashed_without_stopping_container() -> None:
    store = SessionStore()
    store.create("sess-1")
    store.update(
        "sess-1",
        status="running",
        containerPort=9000,
        containerId="old-ctr",
        outputFile="/workspace/sess-1/output/world.archipelago",
    )

    mock_dm = MagicMock()
    result = await restart_server("sess-1", store, PortPool(9000, 9010), mock_dm, image="ap:test")

    assert result["error"] == "not_crashed"
    mock_dm.stop_container.assert_not_called()
    mock_dm.run_container.assert_not_called()


async def test_restart_rejects_crashed_session_without_output_file() -> None:
    store = SessionStore()
    store.create("sess-1")
    store.update("sess-1", status="crashed", outputFile=None, containerPort=9000, containerId="old-ctr")

    mock_dm = MagicMock()
    result = await restart_server("sess-1", store, PortPool(9000, 9010), mock_dm, image="ap:test")

    assert result["error"] == "not_ready"
    mock_dm.stop_container.assert_not_called()


# ─── _health_check_loop ───────────────────────────────────────────────────────

async def test_health_check_crash_after_three_failures() -> None:
    store = SessionStore()
    store.create("sess-1")
    store.update("sess-1", status="running", containerPort=9000, containerId="ctr-1")

    pool = PortPool(9000, 9001)
    pool.allocate()  # 9000 in use

    mock_dm = MagicMock()

    with patch("app.server_lifecycle._tcp_ping", return_value=False):
        await _health_check_loop("sess-1", store, pool, mock_dm, interval=0)

    assert store.get("sess-1")["status"] == "crashed"
    assert store.get("sess-1")["containerPort"] is None
    assert pool.available == 2  # 9000 released, 9001 never taken


async def test_health_check_calls_stop_container_on_crash() -> None:
    store = SessionStore()
    store.create("sess-1")
    store.update("sess-1", status="running", containerPort=9000, containerId="ctr-crash")

    pool = PortPool(9000, 9001)
    pool.allocate()

    mock_dm = MagicMock()

    with patch("app.server_lifecycle._tcp_ping", return_value=False):
        await _health_check_loop("sess-1", store, pool, mock_dm, interval=0)

    mock_dm.stop_container.assert_called_once_with("ctr-crash")


async def test_health_check_resets_failures_on_success() -> None:
    store = SessionStore()
    store.create("sess-1")
    store.update("sess-1", status="running", containerPort=9000, containerId="ctr-1")

    pool = PortPool(9000, 9001)
    pool.allocate()

    call_count = 0

    def _side_effect(host: str, port: int) -> bool:
        nonlocal call_count
        call_count += 1
        if call_count == 1:
            return True  # success resets failures
        # Force loop exit by marking session stopped
        store.update("sess-1", status="stopped")
        return False

    with patch("app.server_lifecycle._tcp_ping", side_effect=_side_effect):
        await _health_check_loop("sess-1", store, pool, MagicMock(), interval=0)

    assert store.get("sess-1")["status"] == "stopped"  # not crashed


async def test_health_check_exits_immediately_when_not_running() -> None:
    store = SessionStore()
    store.create("sess-1")
    store.update("sess-1", status="stopped")

    with patch("app.server_lifecycle._tcp_ping") as mock_ping:
        await _health_check_loop("sess-1", store, PortPool(9000, 9001), MagicMock(), interval=0)

    mock_ping.assert_not_called()


# ─── HTTP endpoint tests ──────────────────────────────────────────────────────

def test_launch_endpoint_returns_200(client: TestClient, monkeypatch) -> None:
    import app.main as main_module

    fresh_store = SessionStore()
    fresh_store.create("sess-http-1")
    fresh_store.update("sess-http-1", status="generated", outputFile="/workspace/sess-http-1/output/world.archipelago")
    monkeypatch.setattr(main_module, "session_store", fresh_store)

    mock_dm = MagicMock()
    mock_dm.run_container.return_value = _make_container("ctr-1")
    monkeypatch.setattr(main_module, "docker_manager", mock_dm)

    with patch("app.server_lifecycle.asyncio.create_task"):
        res = client.post("/sessions/sess-http-1/launch", headers=HEADERS)

    assert res.status_code == 200
    body = res.json()
    assert "containerPort" in body
    assert "serverPassword" in body
    assert body["containerHost"] == "0.0.0.0"


def test_launch_endpoint_returns_404_for_unknown_session(client: TestClient) -> None:
    res = client.post("/sessions/ghost/launch", headers=HEADERS)
    assert res.status_code == 404


def test_launch_endpoint_returns_409_if_already_running(client: TestClient, monkeypatch) -> None:
    import app.main as main_module

    fresh_store = SessionStore()
    fresh_store.create("sess-conflict")
    fresh_store.update("sess-conflict", status="running", containerPort=9050)
    monkeypatch.setattr(main_module, "session_store", fresh_store)

    res = client.post("/sessions/sess-conflict/launch", headers=HEADERS)
    assert res.status_code == 409


def test_delete_session_endpoint_returns_200(client: TestClient, monkeypatch) -> None:
    import app.main as main_module

    fresh_store = SessionStore()
    fresh_store.create("sess-del-1")
    fresh_store.update("sess-del-1", status="running", containerPort=9000, containerId="ctr-del")
    monkeypatch.setattr(main_module, "session_store", fresh_store)

    res = client.delete("/sessions/sess-del-1", headers=HEADERS)
    assert res.status_code == 200
    assert res.json()["status"] == "stopped"


def test_delete_session_endpoint_returns_404_for_unknown(client: TestClient) -> None:
    res = client.delete("/sessions/ghost", headers=HEADERS)
    assert res.status_code == 404


def test_restart_endpoint_returns_200(client: TestClient, monkeypatch) -> None:
    import app.main as main_module

    fresh_store = SessionStore()
    fresh_store.create("sess-rst-1")
    fresh_store.update(
        "sess-rst-1",
        status="crashed",
        containerPort=9000,
        containerId="old-ctr",
        outputFile="/workspace/sess-rst-1/output/world.archipelago",
    )
    monkeypatch.setattr(main_module, "session_store", fresh_store)

    mock_dm = MagicMock()
    mock_dm.run_container.return_value = _make_container("new-ctr")
    monkeypatch.setattr(main_module, "docker_manager", mock_dm)

    with patch("app.server_lifecycle.asyncio.create_task"):
        res = client.post("/sessions/sess-rst-1/restart", headers=HEADERS)

    assert res.status_code == 200
    assert "containerPort" in res.json()


def test_restart_endpoint_returns_404_for_unknown(client: TestClient) -> None:
    res = client.post("/sessions/ghost/restart", headers=HEADERS)
    assert res.status_code == 404


def test_restart_endpoint_returns_409_when_not_crashed(client: TestClient, monkeypatch) -> None:
    import app.main as main_module

    fresh_store = SessionStore()
    fresh_store.create("sess-rst-conflict")
    fresh_store.update(
        "sess-rst-conflict",
        status="running",
        containerPort=9000,
        containerId="old-ctr",
        outputFile="/workspace/sess-rst-conflict/output/world.archipelago",
    )
    monkeypatch.setattr(main_module, "session_store", fresh_store)

    res = client.post("/sessions/sess-rst-conflict/restart", headers=HEADERS)
    assert res.status_code == 409
    assert res.json()["error"] == "not_crashed"


def test_launch_returns_401_without_key(client: TestClient) -> None:
    res = client.post("/sessions/sess-1/launch")
    assert res.status_code == 401


def test_restart_returns_401_without_key(client: TestClient) -> None:
    res = client.post("/sessions/sess-1/restart")
    assert res.status_code == 401


def test_delete_returns_401_without_key(client: TestClient) -> None:
    res = client.delete("/sessions/sess-1")
    assert res.status_code == 401
