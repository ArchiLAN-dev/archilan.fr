from __future__ import annotations

import json
import pathlib
from typing import Any

import yaml


def write_slot_yamls(
    session_id: str,
    slots: list[dict[str, Any]],
    workspace_root: str = "/workspace",
) -> list[str]:
    """
    Write one YAML file per slot to {workspace_root}/{session_id}/yamls/.

    Accepts two slot formats:
    - Apworld: {slotName, apworldStorageKey, playerYaml} - writes playerYaml verbatim.
    - Legacy:  {slotName, archipelagoGameName, options}  - builds YAML from game + options.

    When apworld slots with a download URL are present, writes {session_id}/apworld_urls.json
    ({key: url} mapping) so the generation pipeline can download the files.

    Returns the list of absolute file paths written.
    """
    yamls_dir = pathlib.Path(workspace_root) / session_id / "yamls"
    yamls_dir.mkdir(parents=True, exist_ok=True)

    files: list[str] = []
    apworld_urls: dict[str, str] = {}

    for slot in slots:
        name: str = str(slot.get("slotName", ""))
        dest = yamls_dir / f"{name}.yaml"

        if "apworldStorageKey" in slot:
            player_yaml: str = str(slot.get("playerYaml", ""))
            storage_key: str = str(slot.get("apworldStorageKey", ""))
            download_url: str = str(slot.get("apworldDownloadUrl") or "")

            with dest.open("w", encoding="utf-8") as fh:
                fh.write(player_yaml)

            if download_url:
                if storage_key and storage_key not in apworld_urls:
                    apworld_urls[storage_key] = download_url
        else:
            game: str = str(slot.get("archipelagoGameName", ""))
            options: dict[str, Any] = slot.get("options", {}) if isinstance(slot.get("options"), dict) else {}

            data: dict[str, Any] = {
                "name": name,
                "game": game,
                game: options,
            }

            with dest.open("w", encoding="utf-8") as fh:
                yaml.dump(data, fh, allow_unicode=True, default_flow_style=False, sort_keys=False)

        files.append(str(dest))

    if apworld_urls:
        urls_manifest = pathlib.Path(workspace_root) / session_id / "apworld_urls.json"
        urls_manifest.parent.mkdir(parents=True, exist_ok=True)
        urls_manifest.write_text(json.dumps(apworld_urls), encoding="utf-8")

    return files
