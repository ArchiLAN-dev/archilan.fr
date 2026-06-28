from __future__ import annotations

import pathlib

from fastapi.testclient import TestClient

API_KEY = "test-secret"
HEADERS = {"X-Api-Key": API_KEY}


# ─── Slot name generation ─────────────────────────────────────────────────────

from app.slot_names import abbreviate_game, generate_slot_names, sanitize_player_name, validate_slot_names


def test_abbreviate_single_word() -> None:
    assert abbreviate_game("Celeste") == "C"


def test_abbreviate_skips_stop_words() -> None:
    assert abbreviate_game("The Legend of Zelda") == "LZ"


def test_abbreviate_hollow_knight() -> None:
    assert abbreviate_game("Hollow Knight") == "HK"


def test_abbreviate_max_four_chars() -> None:
    assert len(abbreviate_game("Super Mario Odyssey Deluxe Edition")) <= 4


def test_sanitize_strips_non_alphanumeric() -> None:
    assert sanitize_player_name("Alice-1") == "Alice1"


def test_sanitize_keeps_underscore() -> None:
    # Underscore survives; apostrophes/accents are dropped.
    assert sanitize_player_name("My_Name") == "My_Name"
    assert sanitize_player_name("O'Brien") == "OBrien"


def test_sanitize_fallback_when_empty() -> None:
    assert sanitize_player_name("---") == "Player"


def test_generate_single_slot() -> None:
    names = generate_slot_names([
        {"slotId": "s1", "playerName": "Alice", "archipelagoGameName": "Hollow Knight"},
    ])
    assert names["s1"] == "Alice_HK1"


def test_generate_same_player_same_game_increments() -> None:
    names = generate_slot_names([
        {"slotId": "s1", "playerName": "Alice", "archipelagoGameName": "Hollow Knight"},
        {"slotId": "s2", "playerName": "Alice", "archipelagoGameName": "Hollow Knight"},
    ])
    assert names["s1"] == "Alice_HK1"
    assert names["s2"] == "Alice_HK2"


def test_generate_different_players_same_game() -> None:
    names = generate_slot_names([
        {"slotId": "s1", "playerName": "Alice", "archipelagoGameName": "Hollow Knight"},
        {"slotId": "s2", "playerName": "Bob", "archipelagoGameName": "Hollow Knight"},
    ])
    assert names["s1"] != names["s2"]
    assert all(len(n) <= 16 for n in names.values())


def test_generate_collision_resolution_for_same_sanitized_name() -> None:
    # "Alice-1" and "Alice-2" both sanitize to "Alice1" and "Alice2"
    # different, so no collision in this case
    names = generate_slot_names([
        {"slotId": "s1", "playerName": "Alice!", "archipelagoGameName": "Hollow Knight"},
        {"slotId": "s2", "playerName": "Alice#", "archipelagoGameName": "Hollow Knight"},
    ])
    assert names["s1"] != names["s2"]
    assert all(len(n) <= 16 for n in names.values())


def test_generate_names_never_exceed_16_chars() -> None:
    names = generate_slot_names([
        {"slotId": f"s{i}", "playerName": "Alexandrina", "archipelagoGameName": "Super Metroid"}
        for i in range(5)
    ])
    assert all(len(n) <= 16 for n in names.values())


def test_generate_many_duplicate_names_never_exceed_16_chars() -> None:
    names = generate_slot_names([
        {"slotId": f"s{i}", "playerName": "Alexandrina", "archipelagoGameName": "Super Metroid"}
        for i in range(1001)
    ])
    assert len(set(names.values())) == 1001
    assert all(len(n) <= 16 for n in names.values())


def test_generate_all_names_unique() -> None:
    slots = [
        {"slotId": f"s{i}", "playerName": "Alice", "archipelagoGameName": "Hollow Knight"}
        for i in range(10)
    ]
    names = generate_slot_names(slots)
    assert len(set(names.values())) == 10


def test_validate_slot_names_passes_valid_set() -> None:
    assert validate_slot_names(["Alice_HK1", "Bob_HK1"]) == []


def test_validate_slot_names_detects_too_long() -> None:
    errors = validate_slot_names(["A" * 17])
    assert any("17" in e or "dépasse" in e for e in errors)


def test_validate_slot_names_detects_duplicates() -> None:
    errors = validate_slot_names(["Alice_HK1", "Alice_HK1"])
    assert any("plusieurs" in e for e in errors)


# ─── Preflight endpoint ───────────────────────────────────────────────────────

def test_preflight_valid_slots(client: TestClient) -> None:
    res = client.post(
        "/sessions/sess-1/preflight",
        headers=HEADERS,
        json={
            "slots": [
                {
                    "slotId": "s1",
                    "playerName": "Alice",
                    "archipelagoGameName": "Hollow Knight",
                    "options": [
                        {"key": "logic_level", "required": True, "defaultValue": "standard", "currentValue": None},
                    ],
                }
            ]
        },
    )
    assert res.status_code == 200
    body = res.json()
    assert body["valid"] is True
    assert body["slots"][0]["proposedName"] == "Alice_HK1"
    assert body["slots"][0]["errors"] == []


def test_preflight_missing_game_name_is_invalid(client: TestClient) -> None:
    res = client.post(
        "/sessions/sess-1/preflight",
        headers=HEADERS,
        json={
            "slots": [
                {
                    "slotId": "s1",
                    "playerName": "Alice",
                    "archipelagoGameName": "",
                    "options": [],
                }
            ]
        },
    )
    assert res.status_code == 200
    body = res.json()
    assert body["valid"] is False
    assert len(body["slots"][0]["errors"]) > 0


def test_preflight_required_option_without_value_or_default_is_invalid(client: TestClient) -> None:
    res = client.post(
        "/sessions/sess-1/preflight",
        headers=HEADERS,
        json={
            "slots": [
                {
                    "slotId": "s1",
                    "playerName": "Alice",
                    "archipelagoGameName": "Hollow Knight",
                    "options": [
                        {"key": "logic_level", "required": True, "defaultValue": None, "currentValue": None},
                    ],
                }
            ]
        },
    )
    assert res.status_code == 200
    body = res.json()
    assert body["valid"] is False
    assert any("logic_level" in e for e in body["slots"][0]["errors"])


def test_preflight_required_option_with_empty_value_and_default_is_invalid(client: TestClient) -> None:
    res = client.post(
        "/sessions/sess-1/preflight",
        headers=HEADERS,
        json={
            "slots": [
                {
                    "slotId": "s1",
                    "playerName": "Alice",
                    "archipelagoGameName": "Hollow Knight",
                    "options": [
                        {"key": "logic_level", "required": True, "defaultValue": "", "currentValue": ""},
                    ],
                }
            ]
        },
    )
    body = res.json()
    assert body["valid"] is False
    assert any("logic_level" in e for e in body["slots"][0]["errors"])


def test_preflight_required_option_with_default_is_valid(client: TestClient) -> None:
    res = client.post(
        "/sessions/sess-1/preflight",
        headers=HEADERS,
        json={
            "slots": [
                {
                    "slotId": "s1",
                    "playerName": "Alice",
                    "archipelagoGameName": "Hollow Knight",
                    "options": [
                        {"key": "logic_level", "required": True, "defaultValue": "standard", "currentValue": None},
                    ],
                }
            ]
        },
    )
    assert res.json()["valid"] is True


def test_preflight_multi_slot_same_game_gets_unique_names(client: TestClient) -> None:
    res = client.post(
        "/sessions/sess-1/preflight",
        headers=HEADERS,
        json={
            "slots": [
                {"slotId": "s1", "playerName": "Alice", "archipelagoGameName": "Hollow Knight", "options": []},
                {"slotId": "s2", "playerName": "Alice", "archipelagoGameName": "Hollow Knight", "options": []},
            ]
        },
    )
    body = res.json()
    names = [s["proposedName"] for s in body["slots"]]
    assert len(set(names)) == 2
    assert all(len(n) <= 16 for n in names)


def test_preflight_returns_401_without_key(client: TestClient) -> None:
    res = client.post("/sessions/sess-1/preflight", json={"slots": []})
    assert res.status_code == 401


# ─── Apworld slot preflight ───────────────────────────────────────────────────

def test_preflight_apworld_slot_invalid_without_download_url(
    client: TestClient, tmp_path: pathlib.Path, monkeypatch
) -> None:
    import app.main as main_module

    monkeypatch.setattr(main_module, "WORKSPACE_ROOT", str(tmp_path))

    res = client.post(
        "/sessions/sess-aw/preflight",
        headers=HEADERS,
        json={
            "slots": [
                {
                    "slotId": "s1",
                    "playerName": "Alice",
                    "apworldStorageKey": "abc123.apworld",
                    "playerYaml": "name: Alice_HK1\ngame: Hollow Knight\n",
                }
            ]
        },
    )
    assert res.status_code == 200
    body = res.json()
    assert body["valid"] is False
    assert any("URL" in e or "abc123.apworld" in e for e in body["slots"][0]["errors"])


def test_preflight_apworld_slot_invalid_when_file_missing(
    client: TestClient, tmp_path: pathlib.Path, monkeypatch
) -> None:
    import app.main as main_module

    monkeypatch.setattr(main_module, "WORKSPACE_ROOT", str(tmp_path))

    res = client.post(
        "/sessions/sess-aw/preflight",
        headers=HEADERS,
        json={
            "slots": [
                {
                    "slotId": "s1",
                    "playerName": "Alice",
                    "apworldStorageKey": "missing.apworld",
                    "playerYaml": "name: Alice_HK1\n",
                }
            ]
        },
    )
    assert res.status_code == 200
    body = res.json()
    assert body["valid"] is False
    assert any("introuvable" in e or "missing.apworld" in e for e in body["slots"][0]["errors"])


def test_preflight_apworld_slot_invalid_when_player_yaml_empty(
    client: TestClient, tmp_path: pathlib.Path, monkeypatch
) -> None:
    import app.main as main_module

    monkeypatch.setattr(main_module, "WORKSPACE_ROOT", str(tmp_path))

    res = client.post(
        "/sessions/sess-aw/preflight",
        headers=HEADERS,
        json={
            "slots": [
                {
                    "slotId": "s1",
                    "playerName": "Alice",
                    "apworldStorageKey": "abc123.apworld",
                    "playerYaml": "",
                    "apworldDownloadUrl": "https://minio.example/abc123.apworld?sig=x",
                }
            ]
        },
    )
    assert res.status_code == 200
    body = res.json()
    assert body["valid"] is False
    assert any("playerYaml" in e or "vide" in e for e in body["slots"][0]["errors"])


def test_preflight_apworld_slot_with_download_url_skips_local_file_check(
    client: TestClient, tmp_path: pathlib.Path, monkeypatch
) -> None:
    import app.main as main_module

    monkeypatch.setattr(main_module, "WORKSPACE_ROOT", str(tmp_path))
    # Intentionally do NOT create apworlds dir or file - it should be skipped

    res = client.post(
        "/sessions/sess-aw-url/preflight",
        headers=HEADERS,
        json={
            "slots": [
                {
                    "slotId": "s1",
                    "playerName": "Alice",
                    "apworldStorageKey": "abc123.apworld",
                    "playerYaml": "name: Alice_HK1\ngame: Hollow Knight\n",
                    "apworldDownloadUrl": "https://minio.example/abc123.apworld?sig=x",
                }
            ]
        },
    )
    assert res.status_code == 200
    body = res.json()
    assert body["valid"] is True
    assert body["slots"][0]["errors"] == []
