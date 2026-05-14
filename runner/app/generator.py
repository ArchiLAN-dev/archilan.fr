from __future__ import annotations

import asyncio
import json
import logging
import pathlib
import shlex
import urllib.error
import urllib.request
import zipfile

from .api_notifier import notify_status
from .session_store import SessionStore

logger = logging.getLogger(__name__)

DEFAULT_TIMEOUT = 300  # 5 minutes
DEFAULT_WORLD_DIR_FLAG = "--world_directory"


def _apworld_dest_name(src: pathlib.Path, fallback: str) -> str:
    """Return '{pkg_name}.apworld' by reading the package name from the zip root.

    ArchipelagoGenerate derives the world name from the file stem, so the file
    must be named '{pkg_name}.apworld' - not the SHA-256 storage key name.
    Falls back to *fallback* (the original storage key) if the zip can't be read.
    """
    try:
        with zipfile.ZipFile(src) as zf:
            entries = zf.namelist()
            if entries:
                pkg_name = entries[0].replace("\\", "/").split("/")[0]
                if pkg_name and pkg_name.isidentifier():
                    return f"{pkg_name}.apworld"
    except Exception:
        pass
    return fallback


async def run_generation(
    session_id: str,
    workspace_root: str,
    store: SessionStore,
    *,
    generate_cmd: str,
    timeout: int = DEFAULT_TIMEOUT,
    world_dir_flag: str = DEFAULT_WORLD_DIR_FLAG,
) -> None:
    """
    Run the Archipelago generator as a subprocess and update session state.

    If {session_id}/apworld_urls.json exists, APWorld files are downloaded via
    HTTP from the pre-signed URLs before generation.

    - On success (returncode 0): status → 'generated', outputFile set to the
      first *.archipelago file found in the output directory.
    - On non-zero exit: status → 'failed', error set to stderr.
    - On timeout: subprocess killed, status → 'failed' with a timeout message.
    - On any other exception (e.g. command not found): status → 'failed'.
    """
    session_dir = pathlib.Path(workspace_root) / session_id
    yamls_dir = session_dir / "yamls"
    output_dir = session_dir / "output"
    output_dir.mkdir(parents=True, exist_ok=True)

    cmd = [
        *shlex.split(generate_cmd),
        "--player_files_path", str(yamls_dir),
        "--outputpath", str(output_dir),
    ]

    urls_manifest_path = session_dir / "apworld_urls.json"
    apworlds_dest = session_dir / "apworlds"
    has_apworlds = False

    if urls_manifest_path.exists():
        try:
            apworld_urls: dict[str, str] = json.loads(urls_manifest_path.read_text(encoding="utf-8"))
        except Exception:
            apworld_urls = {}

        if apworld_urls:
            apworlds_dest.mkdir(parents=True, exist_ok=True)

            for key, url in apworld_urls.items():
                try:
                    with urllib.request.urlopen(url) as resp:
                        data: bytes = resp.read()
                except urllib.error.HTTPError as e:
                    msg = f"Failed to download apworld '{key}': HTTP {e.code}"
                    store.update(session_id, status="failed", error=msg)
                    logger.error(
                        "apworld download failed",
                        extra={"request_id": "-", "session_id": session_id, "key": key, "http_code": e.code},
                    )
                    await notify_status(session_id, "failed")
                    return
                except urllib.error.URLError as e:
                    msg = f"Failed to download apworld '{key}': {e.reason}"
                    store.update(session_id, status="failed", error=msg)
                    logger.error(
                        "apworld download failed",
                        extra={"request_id": "-", "session_id": session_id, "key": key, "error": str(e.reason)},
                    )
                    await notify_status(session_id, "failed")
                    return

                tmp_path = apworlds_dest / key
                tmp_path.write_bytes(data)
                dest_name = _apworld_dest_name(tmp_path, key)
                if dest_name != key:
                    tmp_path.rename(apworlds_dest / dest_name)

            has_apworlds = True

    if has_apworlds:
        cmd.extend([world_dir_flag, str(apworlds_dest)])

    logger.info("generation started", extra={"request_id": "-", "session_id": session_id, "cmd": cmd})

    try:
        try:
            proc = await asyncio.create_subprocess_exec(
                *cmd,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE,
            )
        except FileNotFoundError:
            msg = f"Command not found: {cmd[0]!r}. Set ARCHIPELAGO_GENERATE_CMD."
            store.update(session_id, status="failed", error=msg)
            logger.error("generation cmd not found", extra={"request_id": "-", "session_id": session_id, "cmd": cmd[0]})
            await notify_status(session_id, "failed")
            return

        try:
            stdout, stderr = await asyncio.wait_for(proc.communicate(), timeout=timeout)
        except asyncio.TimeoutError:
            proc.kill()
            await proc.communicate()
            msg = f"Generation timed out after {timeout}s."
            store.update(session_id, status="failed", error=msg)
            logger.error("generation timeout", extra={"request_id": "-", "session_id": session_id})
            await notify_status(session_id, "failed")
            return

        if proc.returncode == 0:
            # The binary produces *.archipelago; the Python generator produces AP_*.zip.
            output_files = sorted(output_dir.glob("*.archipelago")) or sorted(output_dir.glob("*.zip"))
            if not output_files:
                msg = f"No .archipelago/.zip output file was produced in {output_dir}."
                store.update(session_id, status="failed", error=msg, outputFile=None)
                logger.error(
                    "generation succeeded without output file",
                    extra={"request_id": "-", "session_id": session_id, "output_dir": str(output_dir)},
                )
                await notify_status(session_id, "failed")
                return

            output_file = str(output_files[0])
            store.update(session_id, status="generated", outputFile=output_file)
            logger.info(
                "generation succeeded",
                extra={"request_id": "-", "session_id": session_id, "output_file": output_file},
            )
            await notify_status(session_id, "generated")
        else:
            error_text = stderr.decode(errors="replace").strip()
            store.update(session_id, status="failed", error=error_text)
            logger.error(
                "generation failed (rc=%d)", proc.returncode,
                extra={"request_id": "-", "session_id": session_id},
            )
            await notify_status(session_id, "failed", logs=error_text or None)

    except Exception as exc:
        store.update(session_id, status="failed", error=str(exc))
        logger.error("generation exception: %s", exc, extra={"request_id": "-", "session_id": session_id})
        await notify_status(session_id, "failed")
