from __future__ import annotations

import hashlib
import pathlib


def store(file_bytes: bytes, filename: str, workspace_root: str = "/workspace") -> str:
    """
    Validate .apworld extension, compute SHA-256, persist to
    {workspace_root}/apworlds/{sha256}.apworld and return the storage key.
    Idempotent: same content is never re-written.
    """
    if not filename.lower().endswith(".apworld"):
        raise ValueError(f"Invalid extension: expected .apworld, got {filename!r}")

    digest = hashlib.sha256(file_bytes).hexdigest()
    storage_key = f"{digest}.apworld"

    apworlds_dir = pathlib.Path(workspace_root) / "apworlds"
    apworlds_dir.mkdir(parents=True, exist_ok=True)

    dest = apworlds_dir / storage_key
    if not dest.exists():
        dest.write_bytes(file_bytes)

    return storage_key


def path(storage_key: str, workspace_root: str = "/workspace") -> pathlib.Path:
    """Return the absolute path for a storage key. Does not check existence."""
    return pathlib.Path(workspace_root) / "apworlds" / storage_key


def exists(storage_key: str, workspace_root: str = "/workspace") -> bool:
    """Return whether the apworld file is present on disk."""
    return path(storage_key, workspace_root).exists()
