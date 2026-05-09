from __future__ import annotations

import asyncio
import json
import logging
import pathlib
import shlex
import shutil
import zipfile

from .api_notifier import notify_status
from .session_store import SessionStore

logger = logging.getLogger(__name__)

DEFAULT_TIMEOUT = 300  # 5 minutes
DEFAULT_WORLD_DIR_FLAG = "--world_directory"


def _apworld_dest_name(src: pathlib.Path, fallback: str) -> str:
    """Return '{pkg_name}.apworld' by reading the package name from the zip root.

    ArchipelagoGenerate derives the world name from the file stem, so the file
    must be named '{pkg_name}.apworld' — not the SHA-256 storage key name.
    Falls back to *fallback* (the original storage key) if the zip can't be read.
    """
    try:
        with zipfile.ZipFile(src) as zf:
            entries = zf.namelist()
            if entries:
                pkg_name = entries[0].split("/")[0]
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

    If {session_id}/apworld_keys.json exists, the referenced .apworld files are
    copied to {session_id}/apworlds/ before generation and world_dir_flag is
    appended to the generate command.

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

    manifest_path = session_dir / "apworld_keys.json"
    if manifest_path.exists():
        try:
            apworld_keys: list[str] = json.loads(manifest_path.read_text(encoding="utf-8"))
        except Exception:
            apworld_keys = []

        if apworld_keys:
            apworlds_src = pathlib.Path(workspace_root) / "apworlds"
            apworlds_dest = session_dir / "apworlds"
            apworlds_dest.mkdir(parents=True, exist_ok=True)

            for key in apworld_keys:
                src = apworlds_src / key
                if not src.exists():
                    logger.warning(
                        "apworld not found",
                        extra={"request_id": "-", "session_id": session_id, "key": key},
                    )
                    continue
                dest_name = _apworld_dest_name(src, key)
                shutil.copy2(src, apworlds_dest / dest_name)

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
            output_files = sorted(output_dir.glob("*.archipelago"))
            if not output_files:
                msg = f"No .archipelago output file was produced in {output_dir}."
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
