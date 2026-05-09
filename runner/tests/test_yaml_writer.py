from __future__ import annotations

import pathlib

import pytest
import yaml
from fastapi.testclient import TestClient

from app.yaml_writer import write_slot_yamls

API_KEY = "test-secret"
HEADERS = {"X-Api-Key": API_KEY}


# ─── write_slot_yamls unit tests ─────────────────────────────────────────────

def test_writes_well_formed_yaml(tmp_path: pathlib.Path) -> None:
    files = write_slot_yamls(
        "sess-1",
        [{"slotName": "Alice_HK1", "archipelagoGameName": "Hollow Knight", "options": {"logic_level": "standard"}}],
        workspace_root=str(tmp_path),
    )
    assert len(files) == 1
    path = pathlib.Path(files[0])
    assert path.exists()
    data = yaml.safe_load(path.read_text())
    assert data["name"] == "Alice_HK1"
    assert data["game"] == "Hollow Knight"
    assert data["Hollow Knight"]["logic_level"] == "standard"


def test_writes_to_correct_directory(tmp_path: pathlib.Path) -> None:
    write_slot_yamls(
        "session-xyz",
        [{"slotName": "Bob_OT1", "archipelagoGameName": "Ocarina of Time", "options": {}}],
        workspace_root=str(tmp_path),
    )
    expected_dir = tmp_path / "session-xyz" / "yamls"
    assert expected_dir.is_dir()
    assert (expected_dir / "Bob_OT1.yaml").exists()


def test_writes_boolean_options_correctly(tmp_path: pathlib.Path) -> None:
    files = write_slot_yamls(
        "sess-2",
        [{"slotName": "Alice_HK1", "archipelagoGameName": "Hollow Knight", "options": {"open_forest": True, "glitches": False}}],
        workspace_root=str(tmp_path),
    )
    data = yaml.safe_load(pathlib.Path(files[0]).read_text())
    assert data["Hollow Knight"]["open_forest"] is True
    assert data["Hollow Knight"]["glitches"] is False


def test_writes_multiple_slots(tmp_path: pathlib.Path) -> None:
    files = write_slot_yamls(
        "sess-3",
        [
            {"slotName": "Alice_HK1", "archipelagoGameName": "Hollow Knight", "options": {}},
            {"slotName": "Alice_HK2", "archipelagoGameName": "Hollow Knight", "options": {}},
            {"slotName": "Bob_OT1", "archipelagoGameName": "Ocarina of Time", "options": {}},
        ],
        workspace_root=str(tmp_path),
    )
    assert len(files) == 3
    names = {pathlib.Path(f).stem for f in files}
    assert names == {"Alice_HK1", "Alice_HK2", "Bob_OT1"}


def test_multi_slot_same_game_each_has_correct_name_field(tmp_path: pathlib.Path) -> None:
    files = write_slot_yamls(
        "sess-4",
        [
            {"slotName": "Alice_HK1", "archipelagoGameName": "Hollow Knight", "options": {"logic_level": "standard"}},
            {"slotName": "Alice_HK2", "archipelagoGameName": "Hollow Knight", "options": {"logic_level": "expert"}},
        ],
        workspace_root=str(tmp_path),
    )
    for f in files:
        data = yaml.safe_load(pathlib.Path(f).read_text())
        slot_name = pathlib.Path(f).stem
        assert data["name"] == slot_name


def test_empty_options_produces_valid_yaml(tmp_path: pathlib.Path) -> None:
    files = write_slot_yamls(
        "sess-5",
        [{"slotName": "Alice_HK1", "archipelagoGameName": "Hollow Knight", "options": {}}],
        workspace_root=str(tmp_path),
    )
    data = yaml.safe_load(pathlib.Path(files[0]).read_text())
    assert data["Hollow Knight"] is None or isinstance(data["Hollow Knight"], dict)


# ─── /sessions/{id}/yamls endpoint tests ─────────────────────────────────────

def test_yamls_endpoint_writes_files(client: TestClient, tmp_path: pathlib.Path, monkeypatch) -> None:
    import app.main as main_module
    monkeypatch.setattr(main_module, "WORKSPACE_ROOT", str(tmp_path))

    res = client.post(
        "/sessions/sess-1/yamls",
        headers=HEADERS,
        json={
            "slots": [
                {
                    "slotName": "Alice_HK1",
                    "archipelagoGameName": "Hollow Knight",
                    "options": {"logic_level": "standard"},
                }
            ]
        },
    )
    assert res.status_code == 200
    body = res.json()
    assert len(body["files"]) == 1
    path = pathlib.Path(body["files"][0])
    assert path.exists()
    data = yaml.safe_load(path.read_text())
    assert data["name"] == "Alice_HK1"


def test_yamls_endpoint_rejects_duplicate_slot_names(client: TestClient) -> None:
    res = client.post(
        "/sessions/sess-1/yamls",
        headers=HEADERS,
        json={
            "slots": [
                {"slotName": "Alice_HK1", "archipelagoGameName": "Hollow Knight", "options": {}},
                {"slotName": "Alice_HK1", "archipelagoGameName": "Hollow Knight", "options": {}},
            ]
        },
    )
    assert res.status_code == 422


def test_yamls_endpoint_rejects_name_exceeding_16_chars(client: TestClient) -> None:
    res = client.post(
        "/sessions/sess-1/yamls",
        headers=HEADERS,
        json={"slots": [{"slotName": "A" * 17, "archipelagoGameName": "Hollow Knight", "options": {}}]},
    )
    assert res.status_code == 422


def test_yamls_endpoint_returns_401_without_key(client: TestClient) -> None:
    res = client.post("/sessions/sess-1/yamls", json={"slots": []})
    assert res.status_code == 401


# ─── Apworld slot support ─────────────────────────────────────────────────────

def test_apworld_slot_writes_player_yaml_verbatim(tmp_path: pathlib.Path) -> None:
    player_yaml = "name: Alice_HK1\ngame: Hollow Knight\nHollow Knight:\n  logic_level: expert\n"
    files = write_slot_yamls(
        "sess-aw",
        [{"slotName": "Alice_HK1", "apworldStorageKey": "abc123.apworld", "playerYaml": player_yaml}],
        workspace_root=str(tmp_path),
    )
    assert len(files) == 1
    written = pathlib.Path(files[0]).read_text(encoding="utf-8")
    assert written == player_yaml


def test_apworld_slot_does_not_add_options_block(tmp_path: pathlib.Path) -> None:
    player_yaml = "name: Bob_C1\ngame: Celeste\n"
    write_slot_yamls(
        "sess-aw2",
        [{"slotName": "Bob_C1", "apworldStorageKey": "def456.apworld", "playerYaml": player_yaml}],
        workspace_root=str(tmp_path),
    )
    content = (tmp_path / "sess-aw2" / "yamls" / "Bob_C1.yaml").read_text()
    data = yaml.safe_load(content)
    assert "Celeste" not in data or isinstance(data.get("Celeste"), type(None))


def test_apworld_slot_writes_manifest(tmp_path: pathlib.Path) -> None:
    import json as json_mod
    write_slot_yamls(
        "sess-manifest",
        [{"slotName": "Alice_HK1", "apworldStorageKey": "abc123.apworld", "playerYaml": "name: x\n"}],
        workspace_root=str(tmp_path),
    )
    manifest = tmp_path / "sess-manifest" / "apworld_keys.json"
    assert manifest.exists()
    keys = json_mod.loads(manifest.read_text())
    assert "abc123.apworld" in keys


def test_legacy_slot_does_not_write_manifest(tmp_path: pathlib.Path) -> None:
    write_slot_yamls(
        "sess-legacy",
        [{"slotName": "Bob_OT1", "archipelagoGameName": "Ocarina of Time", "options": {}}],
        workspace_root=str(tmp_path),
    )
    manifest = tmp_path / "sess-legacy" / "apworld_keys.json"
    assert not manifest.exists()


def test_mixed_slots_write_both_formats(tmp_path: pathlib.Path) -> None:
    player_yaml = "name: Alice_HK1\ngame: Hollow Knight\n"
    files = write_slot_yamls(
        "sess-mixed",
        [
            {"slotName": "Alice_HK1", "apworldStorageKey": "abc.apworld", "playerYaml": player_yaml},
            {"slotName": "Bob_OT1", "archipelagoGameName": "Ocarina of Time", "options": {"logic": "standard"}},
        ],
        workspace_root=str(tmp_path),
    )
    assert len(files) == 2

    aw_content = (tmp_path / "sess-mixed" / "yamls" / "Alice_HK1.yaml").read_text()
    assert aw_content == player_yaml

    legacy_data = yaml.safe_load((tmp_path / "sess-mixed" / "yamls" / "Bob_OT1.yaml").read_text())
    assert legacy_data["game"] == "Ocarina of Time"
    assert legacy_data["Ocarina of Time"]["logic"] == "standard"


def test_apworld_endpoint_writes_apworld_slot(client: TestClient, tmp_path: pathlib.Path, monkeypatch) -> None:
    import app.main as main_module
    monkeypatch.setattr(main_module, "WORKSPACE_ROOT", str(tmp_path))

    player_yaml = "name: Alice_HK1\ngame: Hollow Knight\n"
    res = client.post(
        "/sessions/sess-aw-ep/yamls",
        headers=HEADERS,
        json={
            "slots": [
                {
                    "slotName": "Alice_HK1",
                    "apworldStorageKey": "abc123.apworld",
                    "playerYaml": player_yaml,
                }
            ]
        },
    )
    assert res.status_code == 200
    written = pathlib.Path(res.json()["files"][0]).read_text(encoding="utf-8")
    assert written == player_yaml
