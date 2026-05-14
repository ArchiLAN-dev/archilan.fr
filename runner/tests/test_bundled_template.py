from __future__ import annotations

import pathlib
from unittest.mock import AsyncMock, MagicMock, patch

import pytest
from fastapi.testclient import TestClient

API_KEY = "test-secret"
HEADERS = {"X-Api-Key": API_KEY}


def _make_proc(returncode: int, stdout: bytes = b"", stderr: bytes = b"") -> AsyncMock:
    proc = AsyncMock()
    proc.returncode = returncode
    proc.communicate = AsyncMock(return_value=(stdout, stderr))
    proc.kill = MagicMock()
    return proc


# ─── Happy path ───────────────────────────────────────────────────────────────

def test_bundled_template_returns_yaml(
    client: TestClient, tmp_path: pathlib.Path, monkeypatch
) -> None:
    import app.main as main_module

    monkeypatch.setattr(main_module, "WORKSPACE_ROOT", str(tmp_path))
    monkeypatch.setattr(main_module, "ARCHIPELAGO_TEMPLATE_CMD", "ArchipelagoGenerate")

    yaml_output = b"name: PlayerName\ngame: A Link to the Past\n"
    proc = _make_proc(returncode=0, stdout=yaml_output)

    with (
        patch("asyncio.create_subprocess_exec", return_value=proc),
        patch("tempfile.mkdtemp", return_value=str(tmp_path / "tmp_dir")),
        patch("shutil.rmtree"),
    ):
        res = client.post(
            "/apworld/bundled-template",
            headers=HEADERS,
            json={"gameName": "A Link to the Past"},
        )

    assert res.status_code == 200, res.text
    body = res.json()
    assert body["archipelagoGameName"] == "A Link to the Past"
    assert "A Link to the Past" in body["defaultYaml"]


# ─── Validation ───────────────────────────────────────────────────────────────

def test_bundled_template_missing_game_name_returns_422(client: TestClient) -> None:
    res = client.post("/apworld/bundled-template", headers=HEADERS, json={})
    assert res.status_code == 422
    assert res.json()["error"] == "game_name_required"


def test_bundled_template_empty_game_name_returns_422(client: TestClient) -> None:
    res = client.post("/apworld/bundled-template", headers=HEADERS, json={"gameName": "  "})
    assert res.status_code == 422
    assert res.json()["error"] == "game_name_required"


# ─── Runner not configured ────────────────────────────────────────────────────

def test_bundled_template_no_template_cmd_returns_503(
    client: TestClient, monkeypatch
) -> None:
    import app.main as main_module

    monkeypatch.setattr(main_module, "ARCHIPELAGO_TEMPLATE_CMD", "")

    res = client.post(
        "/apworld/bundled-template",
        headers=HEADERS,
        json={"gameName": "Super Metroid"},
    )
    assert res.status_code == 503
    assert res.json()["error"] == "template_cmd_not_configured"


# ─── Template generation failures ────────────────────────────────────────────

def test_bundled_template_generation_failure_returns_422(
    client: TestClient, tmp_path: pathlib.Path, monkeypatch
) -> None:
    import app.main as main_module

    monkeypatch.setattr(main_module, "WORKSPACE_ROOT", str(tmp_path))
    monkeypatch.setattr(main_module, "ARCHIPELAGO_TEMPLATE_CMD", "ArchipelagoGenerate")

    proc = _make_proc(returncode=1, stderr=b"Unknown game: Fake Game")

    with (
        patch("asyncio.create_subprocess_exec", return_value=proc),
        patch("tempfile.mkdtemp", return_value=str(tmp_path / "tmp_dir")),
        patch("shutil.rmtree"),
    ):
        res = client.post(
            "/apworld/bundled-template",
            headers=HEADERS,
            json={"gameName": "Fake Game"},
        )

    assert res.status_code == 422
    assert res.json()["error"] == "template_failed"


def test_bundled_template_cmd_not_found_returns_503(
    client: TestClient, tmp_path: pathlib.Path, monkeypatch
) -> None:
    import app.main as main_module

    monkeypatch.setattr(main_module, "WORKSPACE_ROOT", str(tmp_path))
    monkeypatch.setattr(main_module, "ARCHIPELAGO_TEMPLATE_CMD", "ArchipelagoGenerate")

    with (
        patch("asyncio.create_subprocess_exec", side_effect=FileNotFoundError),
        patch("tempfile.mkdtemp", return_value=str(tmp_path / "tmp_dir")),
        patch("shutil.rmtree"),
    ):
        res = client.post(
            "/apworld/bundled-template",
            headers=HEADERS,
            json={"gameName": "A Link to the Past"},
        )

    assert res.status_code == 503
    assert res.json()["error"] == "archigenerate_not_found"


def test_bundled_template_requires_api_key(client: TestClient) -> None:
    res = client.post("/apworld/bundled-template", json={"gameName": "Super Metroid"})
    assert res.status_code == 401
