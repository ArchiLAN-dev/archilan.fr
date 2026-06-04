from __future__ import annotations

import asyncio
import io
import pathlib
import zipfile
from unittest.mock import AsyncMock, MagicMock, patch

import pytest
from fastapi.testclient import TestClient

from app.generator import run_generation
from app.session_store import SessionStore

API_KEY = "test-secret"
HEADERS = {"X-Api-Key": API_KEY}


# ─── run_generation coroutine tests ──────────────────────────────────────────

def _make_proc(returncode: int, stdout: bytes = b"", stderr: bytes = b"") -> AsyncMock:
    proc = AsyncMock()
    proc.returncode = returncode
    proc.communicate = AsyncMock(return_value=(stdout, stderr))
    proc.kill = MagicMock()
    return proc


async def test_success_sets_generated_status(tmp_path: pathlib.Path) -> None:
    store = SessionStore()
    store.create("sess-1")

    output_dir = tmp_path / "sess-1" / "output"
    output_dir.mkdir(parents=True)
    (output_dir / "world.archipelago").write_bytes(b"fake-multiworld")

    proc = _make_proc(returncode=0)
    with patch("asyncio.create_subprocess_exec", return_value=proc):
        await run_generation("sess-1", str(tmp_path), store, generate_cmd="FakeGenerate", timeout=10)

    session = store.get("sess-1")
    assert session is not None
    assert session["status"] == "generated"
    assert session["outputFile"] is not None
    assert session["outputFile"].endswith(".archipelago")


async def test_generation_invokes_archipelago_generate_with_yaml_and_output_dirs(tmp_path: pathlib.Path) -> None:
    store = SessionStore()
    store.create("sess-1")

    output_dir = tmp_path / "sess-1" / "output"
    output_dir.mkdir(parents=True)
    (output_dir / "world.archipelago").write_bytes(b"fake-multiworld")

    proc = _make_proc(returncode=0)
    with patch("asyncio.create_subprocess_exec", return_value=proc) as mock_exec:
        await run_generation("sess-1", str(tmp_path), store, generate_cmd="FakeGenerate", timeout=10)

    mock_exec.assert_called_once_with(
        "FakeGenerate",
        "--player_files_path", str(tmp_path / "sess-1" / "yamls"),
        "--outputpath", str(output_dir),
        stdout=asyncio.subprocess.PIPE,
        stderr=asyncio.subprocess.PIPE,
    )


async def test_nonzero_exit_sets_failed_with_stderr(tmp_path: pathlib.Path) -> None:
    store = SessionStore()
    store.create("sess-1")

    proc = _make_proc(returncode=1, stderr=b"fatal error in generator")
    with patch("asyncio.create_subprocess_exec", return_value=proc):
        await run_generation("sess-1", str(tmp_path), store, generate_cmd="FakeGenerate", timeout=10)

    session = store.get("sess-1")
    assert session is not None
    assert session["status"] == "failed"
    assert "fatal error in generator" in session["error"]


async def test_timeout_sets_failed_with_timeout_message(tmp_path: pathlib.Path) -> None:
    store = SessionStore()
    store.create("sess-1")

    proc = AsyncMock()
    proc.kill = MagicMock()
    proc.communicate = AsyncMock(return_value=(b"", b""))

    with (
        patch("asyncio.create_subprocess_exec", return_value=proc),
        patch("app.generator.asyncio.wait_for", side_effect=asyncio.TimeoutError()),
    ):
        await run_generation("sess-1", str(tmp_path), store, generate_cmd="FakeGenerate", timeout=300)

    session = store.get("sess-1")
    assert session is not None
    assert session["status"] == "failed"
    assert "timed out" in session["error"].lower() or "timeout" in session["error"].lower()
    proc.kill.assert_called_once()


async def test_command_not_found_sets_failed(tmp_path: pathlib.Path) -> None:
    store = SessionStore()
    store.create("sess-1")

    with patch("asyncio.create_subprocess_exec", side_effect=FileNotFoundError("command not found")):
        await run_generation("sess-1", str(tmp_path), store, generate_cmd="NoSuchCommand", timeout=10)

    session = store.get("sess-1")
    assert session is not None
    assert session["status"] == "failed"
    assert session["error"] is not None


async def test_success_without_archipelago_file_sets_failed(tmp_path: pathlib.Path) -> None:
    store = SessionStore()
    store.create("sess-1")

    proc = _make_proc(returncode=0)
    with patch("asyncio.create_subprocess_exec", return_value=proc):
        await run_generation("sess-1", str(tmp_path), store, generate_cmd="FakeGenerate", timeout=10)

    session = store.get("sess-1")
    assert session["status"] == "failed"
    assert session["outputFile"] is None
    assert ".archipelago" in session["error"]


# ─── HTTP endpoint tests ──────────────────────────────────────────────────────

def test_generate_returns_202_and_generating_status(client: TestClient, monkeypatch) -> None:
    import app.main as main_module

    fresh_store = SessionStore()
    monkeypatch.setattr(main_module, "session_store", fresh_store)

    with patch("app.main.asyncio.create_task"):
        res = client.post("/sessions/sess-http-1/generate", headers=HEADERS)

    assert res.status_code == 202
    body = res.json()
    assert body["sessionId"] == "sess-http-1"
    assert body["status"] == "generating"


def test_generate_returns_409_when_already_generating(client: TestClient, monkeypatch) -> None:
    import app.main as main_module

    fresh_store = SessionStore()
    monkeypatch.setattr(main_module, "session_store", fresh_store)
    fresh_store.create("sess-conflict")

    with patch("app.generator.asyncio.create_subprocess_exec") as mock_exec:
        mock_exec.return_value = _make_proc(0)
        res = client.post("/sessions/sess-conflict/generate", headers=HEADERS)

    assert res.status_code == 409


def test_get_session_returns_404_for_unknown(client: TestClient) -> None:
    res = client.get("/sessions/nonexistent", headers=HEADERS)
    assert res.status_code == 404


def test_get_session_returns_state_after_trigger(client: TestClient, monkeypatch) -> None:
    import app.main as main_module

    fresh_store = SessionStore()
    monkeypatch.setattr(main_module, "session_store", fresh_store)
    monkeypatch.setattr(main_module, "ARCHIPELAGO_GENERATE_CMD", "FakeGenerate")

    with patch("asyncio.create_task"):
        client.post("/sessions/sess-get-1/generate", headers=HEADERS)

    res = client.get("/sessions/sess-get-1", headers=HEADERS)
    assert res.status_code == 200
    body = res.json()
    assert body["sessionId"] == "sess-get-1"
    assert body["status"] in {"generating", "generated", "failed"}


def test_generate_returns_401_without_key(client: TestClient) -> None:
    res = client.post("/sessions/sess-1/generate", json={})
    assert res.status_code == 401


def test_get_session_returns_401_without_key(client: TestClient) -> None:
    res = client.get("/sessions/sess-1")
    assert res.status_code == 401


# ─── Apworld pipeline ─────────────────────────────────────────────────────────

async def test_generation_ignores_legacy_apworld_keys_manifest(
    tmp_path: pathlib.Path,
) -> None:
    store = SessionStore()
    store.create("sess-aw")

    output_dir = tmp_path / "sess-aw" / "output"
    output_dir.mkdir(parents=True)
    (output_dir / "world.archipelago").write_bytes(b"fake-multiworld")

    apworlds_src = tmp_path / "apworlds"
    apworlds_src.mkdir()
    (apworlds_src / "abc123.apworld").write_bytes(b"fake-apworld-content")

    manifest = tmp_path / "sess-aw" / "apworld_keys.json"
    manifest.parent.mkdir(parents=True, exist_ok=True)
    manifest.write_text('["abc123.apworld"]', encoding="utf-8")

    proc = _make_proc(returncode=0)
    with patch("asyncio.create_subprocess_exec", return_value=proc) as mock_exec:
        await run_generation(
            "sess-aw", str(tmp_path), store,
            generate_cmd="FakeGenerate", timeout=10,
            world_dir_flag="--world_directory",
        )

    cmd_list = list(mock_exec.call_args.args)
    assert "--world_directory" not in cmd_list

    session = store.get("sess-aw")
    assert session is not None
    assert session["status"] == "generated"


async def test_apworld_renamed_to_package_name(tmp_path: pathlib.Path) -> None:
    """Files stored as {sha256}.apworld must be renamed to {pkg_name}.apworld."""
    store = SessionStore()
    store.create("sess-rename")

    output_dir = tmp_path / "sess-rename" / "output"
    output_dir.mkdir(parents=True)
    (output_dir / "world.archipelago").write_bytes(b"fake-multiworld")

    # Build a minimal apworld zip whose root package is "luigismansion"
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as zf:
        zf.writestr("luigismansion/__init__.py", "# stub")

    import json as json_mod
    urls_manifest = tmp_path / "sess-rename" / "apworld_urls.json"
    urls_manifest.parent.mkdir(parents=True, exist_ok=True)
    urls_manifest.write_text(json_mod.dumps({"abcdef1234567890.apworld": "https://minio.example/hash.apworld"}), encoding="utf-8")

    fake_resp = MagicMock()
    fake_resp.read.return_value = buf.getvalue()
    fake_resp.__enter__ = MagicMock(return_value=fake_resp)
    fake_resp.__exit__ = MagicMock(return_value=False)

    proc = _make_proc(returncode=0)
    with (
        patch("asyncio.create_subprocess_exec", return_value=proc) as mock_exec,
        patch("urllib.request.urlopen", return_value=fake_resp),
    ):
        await run_generation(
            "sess-rename", str(tmp_path), store,
            generate_cmd="FakeGenerate", timeout=10,
            world_dir_flag="--world_directory",
        )

    world_dir_idx = list(mock_exec.call_args.args).index("--world_directory")
    world_dir = pathlib.Path(list(mock_exec.call_args.args)[world_dir_idx + 1])
    assert (world_dir / "luigismansion.apworld").exists(), "file should be renamed to pkg name"
    assert not (world_dir / "abcdef1234567890.apworld").exists(), "hash-named file should not remain"


async def test_generation_without_apworld_slots_does_not_append_flag(
    tmp_path: pathlib.Path,
) -> None:
    store = SessionStore()
    store.create("sess-legacy")

    output_dir = tmp_path / "sess-legacy" / "output"
    output_dir.mkdir(parents=True)
    (output_dir / "world.archipelago").write_bytes(b"fake-multiworld")

    proc = _make_proc(returncode=0)
    with patch("asyncio.create_subprocess_exec", return_value=proc) as mock_exec:
        await run_generation(
            "sess-legacy", str(tmp_path), store,
            generate_cmd="FakeGenerate", timeout=10,
        )

    call_args = mock_exec.call_args
    cmd_list = list(call_args.args)
    assert "--world_directory" not in cmd_list

    session = store.get("sess-legacy")
    assert session is not None
    assert session["status"] == "generated"


# ─── APWorld URL download pipeline ────────────────────────────────────────────

async def test_generation_with_apworld_urls_downloads_and_appends_flag(
    tmp_path: pathlib.Path,
) -> None:
    store = SessionStore()
    store.create("sess-url-dl")

    output_dir = tmp_path / "sess-url-dl" / "output"
    output_dir.mkdir(parents=True)
    (output_dir / "world.archipelago").write_bytes(b"fake-multiworld")

    fake_apworld_bytes = b"fake-apworld-content"
    urls_manifest = tmp_path / "sess-url-dl" / "apworld_urls.json"
    urls_manifest.parent.mkdir(parents=True, exist_ok=True)
    import json as json_mod
    urls_manifest.write_text(json_mod.dumps({"abc.apworld": "https://minio.example/abc.apworld"}), encoding="utf-8")

    from unittest.mock import MagicMock

    fake_resp = MagicMock()
    fake_resp.read.return_value = fake_apworld_bytes
    fake_resp.__enter__ = MagicMock(return_value=fake_resp)
    fake_resp.__exit__ = MagicMock(return_value=False)

    proc = _make_proc(returncode=0)
    with (
        patch("asyncio.create_subprocess_exec", return_value=proc) as mock_exec,
        patch("urllib.request.urlopen", return_value=fake_resp),
    ):
        await run_generation(
            "sess-url-dl", str(tmp_path), store,
            generate_cmd="FakeGenerate", timeout=10,
            world_dir_flag="--world_directory",
        )

    cmd_list = list(mock_exec.call_args.args)
    assert "--world_directory" in cmd_list
    world_dir = pathlib.Path(cmd_list[cmd_list.index("--world_directory") + 1])
    assert (world_dir / "abc.apworld").exists()
    assert (world_dir / "abc.apworld").read_bytes() == fake_apworld_bytes

    session = store.get("sess-url-dl")
    assert session is not None
    assert session["status"] == "generated"


async def test_generation_with_apworld_urls_ignores_legacy_keys_manifest(
    tmp_path: pathlib.Path,
) -> None:
    store = SessionStore()
    store.create("sess-mixed-aw")

    output_dir = tmp_path / "sess-mixed-aw" / "output"
    output_dir.mkdir(parents=True)
    (output_dir / "world.archipelago").write_bytes(b"fake-multiworld")

    import json as json_mod
    urls_manifest = tmp_path / "sess-mixed-aw" / "apworld_urls.json"
    urls_manifest.parent.mkdir(parents=True, exist_ok=True)
    urls_manifest.write_text(json_mod.dumps({"url.apworld": "https://minio.example/url.apworld"}), encoding="utf-8")

    keys_manifest = tmp_path / "sess-mixed-aw" / "apworld_keys.json"
    keys_manifest.write_text(json_mod.dumps(["legacy.apworld"]), encoding="utf-8")

    fake_resp = MagicMock()
    fake_resp.read.return_value = b"url-apworld-content"
    fake_resp.__enter__ = MagicMock(return_value=fake_resp)
    fake_resp.__exit__ = MagicMock(return_value=False)

    proc = _make_proc(returncode=0)
    with (
        patch("asyncio.create_subprocess_exec", return_value=proc) as mock_exec,
        patch("urllib.request.urlopen", return_value=fake_resp),
    ):
        await run_generation(
            "sess-mixed-aw", str(tmp_path), store,
            generate_cmd="FakeGenerate", timeout=10,
            world_dir_flag="--world_directory",
        )

    cmd_list = list(mock_exec.call_args.args)
    assert cmd_list.count("--world_directory") == 1
    world_dir = pathlib.Path(cmd_list[cmd_list.index("--world_directory") + 1])
    assert (world_dir / "url.apworld").read_bytes() == b"url-apworld-content"
    assert not (world_dir / "legacy.apworld").exists()

    session = store.get("sess-mixed-aw")
    assert session is not None
    assert session["status"] == "generated"


async def test_generation_apworld_http_error_sets_failed(tmp_path: pathlib.Path) -> None:
    store = SessionStore()
    store.create("sess-http-err")

    import json as json_mod
    import urllib.error

    urls_manifest = tmp_path / "sess-http-err" / "apworld_urls.json"
    urls_manifest.parent.mkdir(parents=True, exist_ok=True)
    urls_manifest.write_text(json_mod.dumps({"bad.apworld": "https://minio.example/bad.apworld"}), encoding="utf-8")

    http_error = urllib.error.HTTPError("https://minio.example/bad.apworld", 403, "Forbidden", {}, None)  # type: ignore[arg-type]

    with patch("urllib.request.urlopen", side_effect=http_error):
        await run_generation(
            "sess-http-err", str(tmp_path), store,
            generate_cmd="FakeGenerate", timeout=10,
        )

    session = store.get("sess-http-err")
    assert session is not None
    assert session["status"] == "failed"
    assert "403" in session["error"] or "bad.apworld" in session["error"]


async def test_generation_apworld_network_error_sets_failed(tmp_path: pathlib.Path) -> None:
    store = SessionStore()
    store.create("sess-net-err")

    import json as json_mod
    import urllib.error

    urls_manifest = tmp_path / "sess-net-err" / "apworld_urls.json"
    urls_manifest.parent.mkdir(parents=True, exist_ok=True)
    urls_manifest.write_text(json_mod.dumps({"bad.apworld": "https://minio.example/bad.apworld"}), encoding="utf-8")

    net_error = urllib.error.URLError("Connection refused")

    with patch("urllib.request.urlopen", side_effect=net_error):
        await run_generation(
            "sess-net-err", str(tmp_path), store,
            generate_cmd="FakeGenerate", timeout=10,
        )

    session = store.get("sess-net-err")
    assert session is not None
    assert session["status"] == "failed"
    assert "bad.apworld" in session["error"] or "Connection refused" in session["error"]


# ─── Seed parameter ───────────────────────────────────────────────────────────

async def test_seed_is_appended_to_cmd(tmp_path: pathlib.Path) -> None:
    store = SessionStore()
    store.create("sess-seed")

    output_dir = tmp_path / "sess-seed" / "output"
    output_dir.mkdir(parents=True)
    (output_dir / "world.archipelago").write_bytes(b"fake")

    proc = _make_proc(returncode=0)
    with patch("asyncio.create_subprocess_exec", return_value=proc) as mock_exec:
        await run_generation("sess-seed", str(tmp_path), store, generate_cmd="FakeGenerate", timeout=10, seed="99999")

    cmd_list = list(mock_exec.call_args.args)
    assert "--seed" in cmd_list
    assert cmd_list[cmd_list.index("--seed") + 1] == "99999"


async def test_no_seed_does_not_append_flag(tmp_path: pathlib.Path) -> None:
    store = SessionStore()
    store.create("sess-noseed")

    output_dir = tmp_path / "sess-noseed" / "output"
    output_dir.mkdir(parents=True)
    (output_dir / "world.archipelago").write_bytes(b"fake")

    proc = _make_proc(returncode=0)
    with patch("asyncio.create_subprocess_exec", return_value=proc) as mock_exec:
        await run_generation("sess-noseed", str(tmp_path), store, generate_cmd="FakeGenerate", timeout=10)

    cmd_list = list(mock_exec.call_args.args)
    assert "--seed" not in cmd_list


# ─── generate-and-launch endpoint ────────────────────────────────────────────

def test_generate_and_launch_success(client: TestClient, monkeypatch: pytest.MonkeyPatch) -> None:
    import app.main as main_module

    fresh_store = SessionStore()
    monkeypatch.setattr(main_module, "session_store", fresh_store)

    async def fake_run_generation(
        session_id: str, workspace_root: str, store: SessionStore,
        *, generate_cmd: str, timeout: int, world_dir_flag: str, seed: str | None = None,
    ) -> None:
        store.update(session_id, status="generated", outputFile="/fake/world.archipelago")

    async def fake_launch_server(session_id, store, port_pool, docker_manager, *, image, workspace_volume=None, bridge_env=None):
        return {"containerHost": "0.0.0.0", "containerPort": 38281, "serverPassword": "s3cr3t"}

    monkeypatch.setattr(main_module, "run_generation", fake_run_generation)
    monkeypatch.setattr(main_module, "launch_server", fake_launch_server)
    monkeypatch.setattr(main_module, "write_slot_yamls", lambda *a, **kw: [])

    res = client.post(
        "/sessions/sess-gal-ok/generate-and-launch",
        headers=HEADERS,
        json={
            "seed": "42",
            "slots": [{"slotName": "Alice", "playerYaml": "name: Alice\n", "apworldStorageKey": "abc.apworld", "apworldDownloadUrl": "http://example.com/abc.apworld"}],
        },
    )

    assert res.status_code == 200
    body = res.json()
    assert body["sessionId"] == "sess-gal-ok"
    assert body["containerPort"] == 38281
    assert body["serverPassword"] == "s3cr3t"


def test_generate_and_launch_generation_failure(client: TestClient, monkeypatch: pytest.MonkeyPatch) -> None:
    import app.main as main_module

    fresh_store = SessionStore()
    monkeypatch.setattr(main_module, "session_store", fresh_store)

    async def fake_run_generation(
        session_id: str, workspace_root: str, store: SessionStore,
        *, generate_cmd: str, timeout: int, world_dir_flag: str, seed: str | None = None,
    ) -> None:
        store.update(session_id, status="failed", error="docker exploded")

    monkeypatch.setattr(main_module, "run_generation", fake_run_generation)
    monkeypatch.setattr(main_module, "write_slot_yamls", lambda *a, **kw: [])

    res = client.post(
        "/sessions/sess-gal-fail/generate-and-launch",
        headers=HEADERS,
        json={"slots": [{"slotName": "Alice", "playerYaml": "name: Alice\n", "apworldStorageKey": "abc.apworld", "apworldDownloadUrl": "http://example.com/abc.apworld"}]},
    )

    assert res.status_code == 503
    body = res.json()
    assert body["error"] == "generation_failed"
    assert "docker exploded" in body["details"]


def test_generate_and_launch_empty_slots_returns_422(client: TestClient, monkeypatch: pytest.MonkeyPatch) -> None:
    import app.main as main_module
    monkeypatch.setattr(main_module, "session_store", SessionStore())

    res = client.post("/sessions/sess-empty/generate-and-launch", headers=HEADERS, json={"slots": []})

    assert res.status_code == 422
    assert res.json()["error"] == "invalid_slots"


def test_generate_and_launch_missing_apworld_download_url_is_accepted(client: TestClient, monkeypatch: pytest.MonkeyPatch) -> None:
    # apworldDownloadUrl is optional: the API may pre-stage the apworld in the workspace.
    # Without a download URL and without pre-staged files, generation proceeds but may fail
    # for other reasons (no world directory). The point here is that the request is NOT
    # rejected at the validation layer (no 422).
    import app.main as main_module
    monkeypatch.setattr(main_module, "session_store", SessionStore())

    res = client.post(
        "/sessions/sess-no-url/generate-and-launch",
        headers=HEADERS,
        json={"slots": [{"slotName": "Alice", "playerYaml": "name: Alice\n", "apworldStorageKey": "abc.apworld"}]},
    )

    assert res.status_code != 422, f"Request was rejected at validation, expected generation attempt: {res.json()}"


def test_generate_and_launch_missing_player_yaml_returns_422(client: TestClient, monkeypatch: pytest.MonkeyPatch) -> None:
    import app.main as main_module
    monkeypatch.setattr(main_module, "session_store", SessionStore())

    res = client.post(
        "/sessions/sess-no-yaml/generate-and-launch",
        headers=HEADERS,
        json={"slots": [{"slotName": "Alice", "playerYaml": "", "apworldStorageKey": "abc.apworld", "apworldDownloadUrl": "http://example.com/abc.apworld"}]},
    )

    assert res.status_code == 422
    body = res.json()
    assert body["error"] == "invalid_slots"
    assert any("playerYaml" in detail for detail in body["details"])


def test_generate_and_launch_session_already_active_returns_409(client: TestClient, monkeypatch: pytest.MonkeyPatch) -> None:
    import app.main as main_module

    fresh_store = SessionStore()
    fresh_store.create("sess-active")
    fresh_store.update("sess-active", status="running")
    monkeypatch.setattr(main_module, "session_store", fresh_store)

    res = client.post(
        "/sessions/sess-active/generate-and-launch",
        headers=HEADERS,
        json={"slots": [{"slotName": "Alice", "playerYaml": "name: Alice\n", "apworldStorageKey": "abc.apworld", "apworldDownloadUrl": "http://example.com/abc.apworld"}]},
    )

    assert res.status_code == 409
    assert res.json()["error"] == "already_active"


def test_generate_and_launch_launch_error_returns_503(client: TestClient, monkeypatch: pytest.MonkeyPatch) -> None:
    import app.main as main_module

    fresh_store = SessionStore()
    monkeypatch.setattr(main_module, "session_store", fresh_store)

    async def fake_run_generation(
        session_id: str, workspace_root: str, store: SessionStore,
        *, generate_cmd: str, timeout: int, world_dir_flag: str, seed: str | None = None,
    ) -> None:
        store.update(session_id, status="generated", outputFile="/fake/world.archipelago")

    async def fake_launch_server(session_id, store, port_pool, docker_manager, *, image, workspace_volume=None, bridge_env=None):
        return {"error": "no_ports_available"}

    monkeypatch.setattr(main_module, "run_generation", fake_run_generation)
    monkeypatch.setattr(main_module, "launch_server", fake_launch_server)
    monkeypatch.setattr(main_module, "write_slot_yamls", lambda *a, **kw: [])

    res = client.post(
        "/sessions/sess-no-ports/generate-and-launch",
        headers=HEADERS,
        json={"slots": [{"slotName": "Alice", "playerYaml": "name: Alice\n", "apworldStorageKey": "abc.apworld", "apworldDownloadUrl": "http://example.com/abc.apworld"}]},
    )

    assert res.status_code == 503
    assert res.json()["error"] == "no_ports_available"


def test_generate_and_launch_write_failed_returns_500(client: TestClient, monkeypatch: pytest.MonkeyPatch) -> None:
    import app.main as main_module

    monkeypatch.setattr(main_module, "session_store", SessionStore())
    monkeypatch.setattr(main_module, "write_slot_yamls", lambda *a, **kw: (_ for _ in ()).throw(OSError("disk full")))

    res = client.post(
        "/sessions/sess-write-fail/generate-and-launch",
        headers=HEADERS,
        json={"slots": [{"slotName": "Alice", "playerYaml": "name: Alice\n", "apworldStorageKey": "abc.apworld", "apworldDownloadUrl": "http://example.com/abc.apworld"}]},
    )

    assert res.status_code == 500
    body = res.json()
    assert body["error"] == "write_failed"
    assert "disk full" in body["details"]


def test_generate_and_launch_seed_is_passed_to_run_generation(client: TestClient, monkeypatch: pytest.MonkeyPatch) -> None:
    import app.main as main_module

    fresh_store = SessionStore()
    monkeypatch.setattr(main_module, "session_store", fresh_store)

    received: list[str | None] = []

    async def fake_run_generation(
        session_id: str, workspace_root: str, store: SessionStore,
        *, generate_cmd: str, timeout: int, world_dir_flag: str, seed: str | None = None,
    ) -> None:
        received.append(seed)
        store.update(session_id, status="generated", outputFile="/fake/world.archipelago")

    async def fake_launch_server(session_id, store, port_pool, docker_manager, *, image, workspace_volume=None, bridge_env=None):
        return {"containerHost": "0.0.0.0", "containerPort": 38281, "serverPassword": None}

    monkeypatch.setattr(main_module, "run_generation", fake_run_generation)
    monkeypatch.setattr(main_module, "launch_server", fake_launch_server)
    monkeypatch.setattr(main_module, "write_slot_yamls", lambda *a, **kw: [])

    client.post(
        "/sessions/sess-seed-prop/generate-and-launch",
        headers=HEADERS,
        json={
            "seed": "99999",
            "slots": [{"slotName": "Alice", "playerYaml": "name: Alice\n", "apworldStorageKey": "abc.apworld", "apworldDownloadUrl": "http://example.com/abc.apworld"}],
        },
    )

    assert received == ["99999"]


# ─── launch-from-file endpoint ────────────────────────────────────────────────

def test_launch_from_file_success(client: TestClient, monkeypatch: pytest.MonkeyPatch) -> None:
    import app.main as main_module

    fresh_store = SessionStore()
    monkeypatch.setattr(main_module, "session_store", fresh_store)

    async def fake_launch_server(session_id, store, port_pool, docker_manager, *, image, workspace_volume=None, bridge_env=None):
        return {"containerHost": "runner.test", "containerPort": 38281, "serverPassword": "s3cr3t"}

    monkeypatch.setattr(main_module, "launch_server", fake_launch_server)

    res = client.post(
        "/sessions/sess-lff-ok/launch-from-file",
        headers=HEADERS,
        json={"outputFile": "/workspace/run-1/output/world.archipelago"},
    )

    assert res.status_code == 200
    body = res.json()
    assert body["containerHost"] == "runner.test"
    assert body["containerPort"] == 38281
    assert body["serverPassword"] == "s3cr3t"


def test_launch_from_file_registers_session_as_generated(client: TestClient, monkeypatch: pytest.MonkeyPatch) -> None:
    import app.main as main_module

    fresh_store = SessionStore()
    monkeypatch.setattr(main_module, "session_store", fresh_store)

    captured: list[dict] = []

    async def fake_launch_server(session_id, store, port_pool, docker_manager, *, image, workspace_volume=None, bridge_env=None):
        session = store.get(session_id)
        if session is not None:
            captured.append(dict(session))
        return {"containerHost": "0.0.0.0", "containerPort": 38281, "serverPassword": None}

    monkeypatch.setattr(main_module, "launch_server", fake_launch_server)

    client.post(
        "/sessions/sess-lff-state/launch-from-file",
        headers=HEADERS,
        json={"outputFile": "/workspace/run-1/output/world.archipelago"},
    )

    assert len(captured) == 1
    assert captured[0]["status"] == "generated"
    assert captured[0]["outputFile"] == "/workspace/run-1/output/world.archipelago"


def test_launch_from_file_missing_output_file_returns_422(client: TestClient, monkeypatch: pytest.MonkeyPatch) -> None:
    import app.main as main_module
    monkeypatch.setattr(main_module, "session_store", SessionStore())

    res = client.post(
        "/sessions/sess-lff-nofile/launch-from-file",
        headers=HEADERS,
        json={},
    )

    assert res.status_code == 422
    body = res.json()
    assert body["error"] == "invalid_request"


def test_launch_from_file_empty_output_file_returns_422(client: TestClient, monkeypatch: pytest.MonkeyPatch) -> None:
    import app.main as main_module
    monkeypatch.setattr(main_module, "session_store", SessionStore())

    res = client.post(
        "/sessions/sess-lff-empty/launch-from-file",
        headers=HEADERS,
        json={"outputFile": "   "},
    )

    assert res.status_code == 422


def test_launch_from_file_launch_error_returns_503(client: TestClient, monkeypatch: pytest.MonkeyPatch) -> None:
    import app.main as main_module

    fresh_store = SessionStore()
    monkeypatch.setattr(main_module, "session_store", fresh_store)

    async def fake_launch_server(session_id, store, port_pool, docker_manager, *, image, workspace_volume=None, bridge_env=None):
        return {"error": "no_ports_available"}

    monkeypatch.setattr(main_module, "launch_server", fake_launch_server)

    res = client.post(
        "/sessions/sess-lff-noport/launch-from-file",
        headers=HEADERS,
        json={"outputFile": "/workspace/run-1/output/world.archipelago"},
    )

    assert res.status_code == 503
    assert res.json()["error"] == "no_ports_available"


def test_launch_from_file_passes_bridge_config(client: TestClient, monkeypatch: pytest.MonkeyPatch) -> None:
    import app.main as main_module

    fresh_store = SessionStore()
    monkeypatch.setattr(main_module, "session_store", fresh_store)

    captured_env: list[dict | None] = []

    async def fake_launch_server(session_id, store, port_pool, docker_manager, *, image, workspace_volume=None, bridge_env=None):
        captured_env.append(bridge_env)
        return {"containerHost": "0.0.0.0", "containerPort": 38281, "serverPassword": None}

    monkeypatch.setattr(main_module, "launch_server", fake_launch_server)

    res = client.post(
        "/sessions/sess-lff-bridge/launch-from-file",
        headers=HEADERS,
        json={
            "outputFile": "/workspace/run-1/output/world.archipelago",
            "bridgeConfig": {"BRIDGE_TOKEN": "tok123", "CENTRAL_URL": "https://api.example.com"},
        },
    )

    assert res.status_code == 200
    assert len(captured_env) == 1
    assert captured_env[0] == {"BRIDGE_TOKEN": "tok123", "CENTRAL_URL": "https://api.example.com"}


def test_launch_from_file_requires_api_key(client: TestClient) -> None:
    res = client.post(
        "/sessions/sess-lff-auth/launch-from-file",
        json={"outputFile": "/workspace/run-1/output/world.archipelago"},
    )

    assert res.status_code == 401
