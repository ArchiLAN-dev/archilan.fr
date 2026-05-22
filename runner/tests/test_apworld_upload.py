from __future__ import annotations

import asyncio
import hashlib
import io
import json
import pathlib
import zipfile
from unittest.mock import AsyncMock, MagicMock, patch

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
    monkeypatch.setattr(main_module, "ARCHIPELAGO_TEMPLATE_CMD", "fake_generate_template")

    apworld_bytes = _make_apworld_bytes("Hollow Knight")
    expected_hash = hashlib.sha256(apworld_bytes).hexdigest()

    template_yaml = "name: PlayerName\ngame: Hollow Knight\n"

    proc = _make_proc(returncode=0, stdout=template_yaml.encode())

    with (
        patch("asyncio.create_subprocess_exec", return_value=proc),
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

def test_upload_apworld_without_archipelago_json_nor_init_returns_422(
    client: TestClient, tmp_path: pathlib.Path, monkeypatch
) -> None:
    """A ZIP with no archipelago.json AND no __init__.py is rejected."""
    import app.main as main_module

    monkeypatch.setattr(main_module, "WORKSPACE_ROOT", str(tmp_path))

    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as zf:
        zf.writestr("README.txt", "no metadata here")
    bad_bytes = buf.getvalue()

    res = client.post(
        "/apworld/upload",
        headers=HEADERS,
        files={"file": ("bad_game.apworld", bad_bytes, "application/octet-stream")},
    )
    assert res.status_code == 422
    body = res.json()
    assert "introuvable" in body.get("detail", "").lower() or "archipelago.json" in body.get("detail", "")


def test_upload_apworld_without_archipelago_json_but_with_init_succeeds(
    client: TestClient, tmp_path: pathlib.Path, monkeypatch
) -> None:
    """Older APWorld format (no archipelago.json) is accepted if __init__.py has game = '...'."""
    import app.main as main_module

    monkeypatch.setattr(main_module, "WORKSPACE_ROOT", str(tmp_path))
    monkeypatch.setattr(main_module, "ARCHIPELAGO_TEMPLATE_CMD", "")

    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as zf:
        zf.writestr(
            "my_game/__init__.py",
            'from worlds.base.world import World\nclass MyWorld(World):\n    game = "My Cool Game"\n',
        )
        zf.writestr("my_game/items.py", "")
    apworld_bytes = buf.getvalue()

    res = client.post(
        "/apworld/upload",
        headers=HEADERS,
        files={"file": ("my_game.apworld", apworld_bytes, "application/octet-stream")},
    )
    assert res.status_code == 200
    body = res.json()
    assert body["archipelagoGameName"] == "My Cool Game"


# ─── Subprocess timeout ───────────────────────────────────────────────────────

def test_upload_template_timeout_succeeds_without_yaml(
    client: TestClient, tmp_path: pathlib.Path, monkeypatch
) -> None:
    """Timeout during template generation is non-fatal: apworld is accepted, defaultYaml is empty."""
    import app.main as main_module

    monkeypatch.setattr(main_module, "WORKSPACE_ROOT", str(tmp_path))
    monkeypatch.setattr(main_module, "ARCHIPELAGO_TEMPLATE_CMD", "fake_generate_template")
    monkeypatch.setattr(main_module, "APWORLD_TEMPLATE_TIMEOUT", 1)

    apworld_bytes = _make_apworld_bytes("Celeste")

    proc = _make_proc(returncode=0, stdout=b"")

    with (
        patch("asyncio.create_subprocess_exec", return_value=proc),
        patch("app.main.asyncio.wait_for", side_effect=asyncio.TimeoutError()),
    ):
        res = client.post(
            "/apworld/upload",
            headers=HEADERS,
            files={"file": ("celeste.apworld", apworld_bytes, "application/octet-stream")},
        )

    assert res.status_code == 200
    assert res.json()["archipelagoGameName"] == "Celeste"
    assert res.json()["defaultYaml"] == ""


# ─── Auth ─────────────────────────────────────────────────────────────────────

def test_upload_without_key_returns_401(client: TestClient) -> None:
    apworld_bytes = _make_apworld_bytes()
    res = client.post(
        "/apworld/upload",
        files={"file": ("game.apworld", apworld_bytes, "application/octet-stream")},
    )
    assert res.status_code == 401
