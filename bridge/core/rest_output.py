from __future__ import annotations

import io
import os
import zipfile
from pathlib import Path

from fastapi import APIRouter, HTTPException
from fastapi.responses import StreamingResponse

router = APIRouter(tags=["Output files"])

_OUTPUT_DIR = Path(os.environ.get("AP_OUTPUT_DIR", "/data/output"))

_FORBIDDEN_EXTENSIONS = {".archipelago"}
_FORBIDDEN_SUBSTRINGS = {"_spoiler"}


def _is_allowed(name: str) -> bool:
    low = name.lower()
    if Path(name).suffix.lower() in _FORBIDDEN_EXTENSIONS:
        return False
    return not any(s in low for s in _FORBIDDEN_SUBSTRINGS)


def _list_patch_files() -> list[str]:
    """Return allowed patch files, looking inside zip archives when needed."""
    if not _OUTPUT_DIR.is_dir():
        return []

    results: list[str] = []

    for entry in sorted(_OUTPUT_DIR.iterdir()):
        if not entry.is_file():
            continue
        if entry.suffix.lower() == ".zip":
            try:
                with zipfile.ZipFile(entry) as zf:
                    for name in zf.namelist():
                        if _is_allowed(name):
                            results.append(name)
            except zipfile.BadZipFile:
                pass
        elif _is_allowed(entry.name):
            results.append(entry.name)

    return results


def _read_patch_file(filename: str) -> bytes | None:
    """
    Read a patch file either directly from the output dir or from inside a zip.
    Returns None if not found or forbidden.
    """
    if not _is_allowed(filename):
        return None

    # Direct file
    direct = _OUTPUT_DIR / filename
    if direct.is_file():
        return direct.read_bytes()

    # Search inside zip archives
    for entry in sorted(_OUTPUT_DIR.iterdir()):
        if not entry.is_file() or entry.suffix.lower() != ".zip":
            continue
        try:
            with zipfile.ZipFile(entry) as zf:
                if filename in zf.namelist():
                    return zf.read(filename)
        except zipfile.BadZipFile:
            pass

    return None


@router.get("/output")
def list_output_files() -> dict[str, list[str]]:
    return {"files": _list_patch_files()}


@router.get("/output/{filename}")
def download_output_file(filename: str) -> StreamingResponse:
    safe = Path(filename).name
    if safe != filename:
        raise HTTPException(status_code=403, detail="Forbidden")

    data = _read_patch_file(safe)
    if data is None:
        raise HTTPException(status_code=404, detail="File not found")

    return StreamingResponse(
        io.BytesIO(data),
        media_type="application/octet-stream",
        headers={"Content-Disposition": f'attachment; filename="{safe}"'},
    )
