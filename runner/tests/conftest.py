from __future__ import annotations

import os
from unittest.mock import MagicMock

import pytest
from fastapi.testclient import TestClient

os.environ.setdefault("RUNNER_API_KEY", "test-secret")
os.environ.setdefault("PORT_RANGE_START", "9000")
os.environ.setdefault("PORT_RANGE_END", "9099")

import app.main as main_module  # noqa: E402  (must come after env setup)
from app.docker_manager import DockerManager  # noqa: E402
from app.port_pool import PortPool  # noqa: E402

API_KEY = "test-secret"


@pytest.fixture()
def mock_docker() -> MagicMock:
    manager = MagicMock(spec=DockerManager)
    manager.connect.return_value = True
    manager.is_connected.return_value = True
    manager.stop_all.return_value = None
    return manager


@pytest.fixture()
def client(mock_docker: MagicMock, monkeypatch: pytest.MonkeyPatch) -> TestClient:
    fresh_pool = PortPool(9000, 9099)
    monkeypatch.setattr(main_module, "docker_manager", mock_docker)
    monkeypatch.setattr(main_module, "port_pool", fresh_pool)
    with TestClient(main_module.app, raise_server_exceptions=True) as c:
        yield c
