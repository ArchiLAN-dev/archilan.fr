from __future__ import annotations

import asyncio
import hashlib
import io
import json
import pathlib
import zipfile
from unittest.mock import AsyncMock, MagicMock, patch

import pytest
from fastapi.testclient import TestClient

API_KEY = "test-secret"
HEADERS = {"X-Api-Key": API_KEY}


def _make_apworld_bytes(game_name: str = "Hollow Knight") -> bytes:
    """Build a minimal valid .apworld ZIP with archipelago.json."""
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as zf:
        zf.writestr("archipelago.json", json.dumps({"game": game_name}))
    return buf.getvalue()


def _make_proc(returncode: int, stdout: bytes = b"", stderr: bytes = b"") -> AsyncMock:
    proc = AsyncMock()
    proc.returncode = returncode
    proc.communicate = AsyncMock(return_value=(stdout, stderr))
    proc.kill = MagicMock()
    return proc


# ─── Happy path ───────────────────────────────────────────────────────────────

def test_upload_valid_apworld_returns_storage_info(
    client: TestClient, tmp_path: pathlib.Path, monkeypatch
) -> None:
    import app.main as main_module

    monkeypatch.setattr(main_module, "WORKSPACE_ROOT", str(tmp_path))

    apworld_bytes = _make_apworld_bytes("Hollow Knight")
    expected_hash = hashlib.sha256(apworld_bytes).hexdigest()

    template_yaml = "name: PlayerName\ngame: Hollow Knight\n"
    template_dir = tmp_path / "_tmpl"
    template_dir.mkdir()
    (template_dir / "Hollow Knight.yaml").write_text(template_yaml)

    proc = _make_proc(returncode=0, stdout=b"")

    with (
        patch("asyncio.create_subprocess_exec", return_value=proc),
        patch("tempfile.mkdtemp", return_value=str(template_dir)),
        patch("shutil.rmtree"),
    ):
        res = client.post(
            "/apworld/upload",
            headers=HEADERS,
            files={"file": ("my_game.apworld", apworld_bytes, "application/octet-stream")},
        )

    assert res.status_code == 200, res.text
    body = res.json()
    assert body["hash"] == expected_hash
    assert body["storageKey"] == f"{expected_hash}.apworld"
    assert body["archipelagoGameName"] == "Hollow Knight"
    assert "Hollow Knight" in body["defaultYaml"]

    stored = pathlib.Path(tmp_path) / "apworlds" / f"{expected_hash}.apworld"
    assert stored.exists()


# ─── Extension validation ─────────────────────────────────────────────────────

def test_upload_wrong_extension_returns_422(client: TestClient) -> None:
    res = client.post(
        "/apworld/upload",
        headers=HEADERS,
        files={"file": ("my_game.zip", b"not-an-apworld", "application/zip")},
    )
    assert res.status_code == 422
    assert "apworld" in res.json().get("detail", "").lower()


# ─── Missing archipelago.json ─────────────────────────────────────────────────

def test_upload_apworld_without_archipelago_json_returns_422(
    client: TestClient, tmp_path: pathlib.Path, monkeypatch
) -> None:
    import app.main as main_module

    monkeypatch.setattr(main_module, "WORKSPACE_ROOT", str(tmp_path))

    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as zf:
        zf.writestr("README.txt", "no archipelago.json here")
    bad_bytes = buf.getvalue()

    res = client.post(
        "/apworld/upload",
        headers=HEADERS,
        files={"file": ("bad_game.apworld", bad_bytes, "application/octet-stream")},
    )
    assert res.status_code == 422
    body = res.json()
    assert "archipelago.json" in body.get("detail", "")


# ─── Subprocess timeout ───────────────────────────────────────────────────────

def test_upload_template_timeout_returns_422(
    client: TestClient, tmp_path: pathlib.Path, monkeypatch
) -> None:
    import app.main as main_module

    monkeypatch.setattr(main_module, "WORKSPACE_ROOT", str(tmp_path))
    monkeypatch.setattr(main_module, "APWORLD_TEMPLATE_TIMEOUT", 1)

    apworld_bytes = _make_apworld_bytes("Celeste")

    proc = AsyncMock()
    proc.kill = MagicMock()
    proc.communicate = AsyncMock(return_value=(b"", b""))

    with (
        patch("asyncio.create_subprocess_exec", return_value=proc),
        patch("app.main.asyncio.wait_for", side_effect=asyncio.TimeoutError()),
    ):
        res = client.post(
            "/apworld/upload",
            headers=HEADERS,
            files={"file": ("celeste.apworld", apworld_bytes, "application/octet-stream")},
        )

    assert res.status_code == 422
    assert "timeout" in res.json().get("detail", "").lower() or "expir" in res.json().get("detail", "").lower()


# ─── Auth ─────────────────────────────────────────────────────────────────────

def test_upload_without_key_returns_401(client: TestClient) -> None:
    apworld_bytes = _make_apworld_bytes()
    res = client.post(
        "/apworld/upload",
        files={"file": ("game.apworld", apworld_bytes, "application/octet-stream")},
    )
    assert res.status_code == 401
