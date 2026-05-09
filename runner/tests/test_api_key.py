from __future__ import annotations

from fastapi.testclient import TestClient

API_KEY = "test-secret"


def test_missing_key_returns_401(client: TestClient) -> None:
    res = client.get("/health")
    assert res.status_code == 401


def test_wrong_key_returns_401(client: TestClient) -> None:
    res = client.get("/health", headers={"X-Api-Key": "wrong-key"})
    assert res.status_code == 401


def test_correct_key_allows_access(client: TestClient) -> None:
    res = client.get("/health", headers={"X-Api-Key": API_KEY})
    assert res.status_code == 200


def test_health_response_shape(client: TestClient) -> None:
    res = client.get("/health", headers={"X-Api-Key": API_KEY})
    body = res.json()
    assert body["status"] in {"ok", "degraded"}
    assert isinstance(body["docker"]["connected"], bool)
    assert isinstance(body["ports"]["total"], int)
    assert isinstance(body["ports"]["available"], int)
    assert isinstance(body["ports"]["in_use"], int)


def test_response_carries_request_id_header(client: TestClient) -> None:
    res = client.get("/health", headers={"X-Api-Key": API_KEY})
    assert "x-request-id" in {k.lower() for k in res.headers}


def test_docker_disconnected_returns_degraded(client: TestClient, mock_docker, monkeypatch) -> None:
    import app.main as main_module

    mock_docker.is_connected.return_value = False
    monkeypatch.setattr(main_module, "docker_manager", mock_docker)

    res = client.get("/health", headers={"X-Api-Key": API_KEY})
    assert res.status_code == 200
    assert res.json()["status"] == "degraded"
    assert res.json()["docker"]["connected"] is False
