from __future__ import annotations

import asyncio
import glob as _glob
import logging
import os as _os
import signal as _signal
import shlex as _shlex
from datetime import datetime
from datetime import timezone as _timezone
from typing import Any

from fastapi import APIRouter, Depends, HTTPException

from .ap_client import ArchipelagoClient
from .coordinator import PauseResumeCoordinator
from .deps import BroadcastFn, get_ap_client, get_bridge_state, get_broadcast, get_coordinator, require_auth
from .schemas import CommandRequest, DeathLinkRequest, HealthResponse, OkResponse, ResumeRequest
from .state import StateManager
from .wake_on_connect import WakeOnConnectServer

log = logging.getLogger("bridge.rest_session")


# ---------------------------------------------------------------------------
# Router
# ---------------------------------------------------------------------------

router = APIRouter(tags=["Session"])


# ---------------------------------------------------------------------------
# Pause flow helpers
# ---------------------------------------------------------------------------

async def _poll_for_save(ap_client: ArchipelagoClient) -> str | None:
    """Send /save to AP, then poll save_dir for an .apsave file (30s timeout)."""
    save_dir = ap_client._config.save_dir

    if ap_client.ws_connected:
        await ap_client.send_command("/save")
        await asyncio.sleep(2)

    loop = asyncio.get_event_loop()
    deadline = loop.time() + 30
    while loop.time() < deadline:
        save_files = sorted(
            _glob.glob(_os.path.join(save_dir, "*.apsave")),
            key=_os.path.getmtime,
        )
        if save_files:
            log.info("pause: found save file %s", save_files[-1])
            return save_files[-1]
        await asyncio.sleep(2)

    log.warning("pause: no .apsave found after 30s")
    return None


def _boto3_upload(endpoint: str, access_key: str, secret_key: str, bucket: str, key: str, body: bytes) -> None:
    import boto3  # noqa: PLC0415
    client = boto3.client(
        "s3",
        endpoint_url=endpoint,
        aws_access_key_id=access_key,
        aws_secret_access_key=secret_key,
    )
    client.put_object(Bucket=bucket, Key=key, Body=body)


async def _upload_save(ap_client: ArchipelagoClient, save_path: str) -> str | None:
    """Upload save file to S3; returns the bare filename or None on failure."""
    config = ap_client._config

    if not all([config.storage_endpoint, config.storage_access_key, config.storage_secret_key]):
        log.warning("pause: storage not configured, skipping upload")
        return None

    timestamp = datetime.now(_timezone.utc).strftime("%Y%m%dT%H%M%S")
    filename = f"{timestamp}.apsave"

    try:
        with open(save_path, "rb") as fh:
            body = fh.read()
        loop = asyncio.get_event_loop()
        await loop.run_in_executor(
            None,
            _boto3_upload,
            config.storage_endpoint,
            config.storage_access_key,
            config.storage_secret_key,
            config.storage_bucket,
            filename,
            body,
        )
        log.info("pause: uploaded save, key=%s", filename)
        return filename
    except Exception as exc:
        log.error("pause: storage upload failed: %s", exc)
        return None


async def _kill_ap(pid_file: str) -> None:
    """Send SIGTERM to the AP process identified by pid_file; SIGKILL after 5s."""
    try:
        with open(pid_file) as fh:
            pid = int(fh.read().strip())
    except FileNotFoundError:
        log.warning("pause: PID file not found: %s", pid_file)
        return
    except (ValueError, OSError) as exc:
        log.warning("pause: cannot read PID file: %s", exc)
        return

    try:
        _os.kill(pid, _signal.SIGTERM)
        log.info("pause: sent SIGTERM to AP pid=%d", pid)
    except ProcessLookupError:
        log.info("pause: AP process already gone pid=%d", pid)
        return
    except OSError as exc:
        log.error("pause: cannot signal AP process: %s", exc)
        return

    for _ in range(25):
        await asyncio.sleep(0.2)
        try:
            _os.kill(pid, 0)
        except ProcessLookupError:
            log.info("pause: AP process exited cleanly pid=%d", pid)
            return

    try:
        _os.kill(pid, _signal.SIGKILL)  # type: ignore[attr-defined]
        log.warning("pause: sent SIGKILL to AP pid=%d (SIGTERM ignored)", pid)
    except ProcessLookupError:
        pass


async def _pause_flow(
    ap_client: ArchipelagoClient,
    coordinator: PauseResumeCoordinator,
    broadcast: BroadcastFn,
) -> None:
    """Full pause flow: save, upload, kill AP, start TCP listener, broadcast lifecycle."""
    session_id = ap_client._config.session_id
    log.info("pause: flow started session_id=%s", session_id)

    save_path = await _poll_for_save(ap_client)
    failed_save = save_path is None

    save_key = await _upload_save(ap_client, save_path) if save_path else None

    await _kill_ap(ap_client._config.ap_pid_file)

    try:
        ap_port = int(ap_client._config.ap_ws_url.rsplit(":", 1)[-1])
        coordinator.wake_stop_event = asyncio.Event()
        server = WakeOnConnectServer(
            ap_port,
            coordinator.wake_stop_event,
            lambda: _wake_on_connect_flow(ap_client, coordinator, broadcast),
        )
        coordinator.wake_task = asyncio.create_task(server.serve())
        log.info("pause: wake-on-connect listener task started")
    except (ValueError, IndexError) as exc:
        log.error("pause: cannot parse AP port from %s: %s", ap_client._config.ap_ws_url, exc)

    await broadcast("lifecycle", {
        "sessionId": session_id,
        "event": "paused",
        "saveKey": save_key,
        "failedSave": failed_save,
    })
    log.info("pause: flow complete save_key=%s failed=%s", save_key, failed_save)


# ---------------------------------------------------------------------------
# Resume flow helpers
# ---------------------------------------------------------------------------

async def _find_local_save(save_dir: str) -> str | None:
    files = sorted(
        _glob.glob(_os.path.join(save_dir, "*.apsave")),
        key=_os.path.getmtime,
    )
    return files[-1] if files else None


async def _download_save_from_storage(ap_client: ArchipelagoClient, save_key: str) -> str | None:
    """Download save from S3 into save_dir and return the local path."""
    config = ap_client._config
    if not all([config.storage_endpoint, config.storage_access_key, config.storage_secret_key]):
        log.warning("resume: storage not configured, cannot download save")
        return None

    dest_path = _os.path.join(config.save_dir, _os.path.basename(save_key))
    try:
        loop = asyncio.get_event_loop()
        contents = await loop.run_in_executor(
            None,
            _boto3_download,
            config.storage_endpoint,
            config.storage_access_key,
            config.storage_secret_key,
            config.storage_bucket,
            save_key,
        )
        with open(dest_path, "wb") as fh:
            fh.write(contents)
        log.info("resume: downloaded save key=%s", save_key)
        return dest_path
    except Exception as exc:
        log.error("resume: storage download failed: %s", exc)
        return None


def _boto3_download(endpoint: str, access_key: str, secret_key: str, bucket: str, key: str) -> bytes:
    import boto3  # noqa: PLC0415
    client = boto3.client(
        "s3",
        endpoint_url=endpoint,
        aws_access_key_id=access_key,
        aws_secret_access_key=secret_key,
    )
    response = client.get_object(Bucket=bucket, Key=key)
    return response["Body"].read()


async def _launch_ap(ap_client: ArchipelagoClient, save_path: str) -> "asyncio.subprocess.Process | None":
    config = ap_client._config
    if not config.ap_launch_cmd:
        log.warning("resume: AP_LAUNCH_CMD not configured, cannot launch AP")
        return None

    cmd = f"{config.ap_launch_cmd} --savefile={_shlex.quote(save_path)}"
    log.info("resume: launching AP: %s", cmd)
    try:
        process = await asyncio.create_subprocess_shell(
            cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
        try:
            with open(config.ap_pid_file, "w") as fh:
                fh.write(str(process.pid))
        except OSError as exc:
            log.warning("resume: cannot write AP pid file %s: %s", config.ap_pid_file, exc)
        return process
    except Exception as exc:
        log.error("resume: AP launch failed: %s", exc)
        return None


async def _wait_for_ap_health(ap_client: ArchipelagoClient, timeout: float = 60.0) -> bool:
    """TCP-probe the AP port every 2s until it accepts a connection or timeout."""
    try:
        ap_port = int(ap_client._config.ap_ws_url.rsplit(":", 1)[-1])
    except (ValueError, IndexError):
        log.error("resume: cannot parse AP port from %s", ap_client._config.ap_ws_url)
        return False

    deadline = asyncio.get_event_loop().time() + timeout
    while asyncio.get_event_loop().time() < deadline:
        try:
            reader, writer = await asyncio.wait_for(
                asyncio.open_connection("localhost", ap_port),
                timeout=2.0,
            )
            writer.close()
            try:
                await writer.wait_closed()
            except Exception:
                pass
            log.info("resume: AP is accepting connections on port %d", ap_port)
            return True
        except (OSError, asyncio.TimeoutError):
            await asyncio.sleep(2)

    log.warning("resume: AP did not become healthy after %.0fs", timeout)
    return False


async def _cancel_wake_task(coordinator: PauseResumeCoordinator) -> None:
    if coordinator.wake_stop_event is not None:
        coordinator.wake_stop_event.set()
    if coordinator.wake_task is not None and not coordinator.wake_task.done():
        log.info("resume: cancelling wake-on-connect listener")
        try:
            await asyncio.wait_for(asyncio.shield(coordinator.wake_task), timeout=3.0)
        except (asyncio.TimeoutError, asyncio.CancelledError):
            coordinator.wake_task.cancel()
    coordinator.wake_task = None
    coordinator.wake_stop_event = None


async def _resume_flow(
    ap_client: ArchipelagoClient,
    last_save_key: str | None,
    coordinator: PauseResumeCoordinator,
    broadcast: BroadcastFn,
) -> None:
    """Full resume flow: cancel wake listener, find save, launch AP, health-check, broadcast."""
    session_id = ap_client._config.session_id
    log.info("resume: flow started session_id=%s", session_id)

    await _cancel_wake_task(coordinator)

    save_path = await _find_local_save(ap_client._config.save_dir)

    if save_path is None and last_save_key:
        save_path = await _download_save_from_storage(ap_client, last_save_key)

    if save_path is None:
        log.error("resume: no save file available, cannot restart AP")
        await broadcast("lifecycle", {"sessionId": session_id, "event": "restart_failed"})
        return

    process = await _launch_ap(ap_client, save_path)
    if process is None:
        log.error("resume: AP launch failed, cannot restart")
        await broadcast("lifecycle", {"sessionId": session_id, "event": "restart_failed"})
        return

    healthy = await _wait_for_ap_health(ap_client)
    if not healthy:
        log.error("resume: AP did not become healthy, restart failed")
        await broadcast("lifecycle", {"sessionId": session_id, "event": "restart_failed"})
        if process.returncode is None:
            process.kill()
        return

    await broadcast("lifecycle", {"sessionId": session_id, "event": "restarted"})
    log.info("resume: flow complete")


# ---------------------------------------------------------------------------
# Wake-on-connect flow
# ---------------------------------------------------------------------------

async def _wake_on_connect_flow(
    ap_client: ArchipelagoClient,
    coordinator: PauseResumeCoordinator,
    broadcast: BroadcastFn,
) -> None:
    """Automatic restart triggered by a player TCP connection."""
    session_id = ap_client._config.session_id
    log.info("wake: on_connect triggered session_id=%s", session_id)

    approved = await coordinator.request_approve_restart()
    if not approved:
        log.info("wake: restart not approved by any WS client, aborted")
        return

    save_path = await _find_local_save(ap_client._config.save_dir)
    if save_path is None:
        log.error("wake: no local save found, restart aborted")
        await broadcast("lifecycle", {"sessionId": session_id, "event": "restart_failed"})
        return

    process = await _launch_ap(ap_client, save_path)
    if process is None:
        log.error("wake: AP launch failed")
        await broadcast("lifecycle", {"sessionId": session_id, "event": "restart_failed"})
        return

    healthy = await _wait_for_ap_health(ap_client)
    if not healthy:
        log.error("wake: AP did not become healthy")
        await broadcast("lifecycle", {"sessionId": session_id, "event": "restart_failed"})
        if process.returncode is None:
            process.kill()
        return

    await broadcast("lifecycle", {"sessionId": session_id, "event": "restarted"})
    log.info("wake: restart complete")


# ---------------------------------------------------------------------------
# Noop broadcast
# ---------------------------------------------------------------------------

async def _noop_broadcast(*_: object) -> None:
    pass


# ---------------------------------------------------------------------------
# Route handlers — public
# ---------------------------------------------------------------------------

@router.get("/health", response_model=HealthResponse)
async def health(
    ap_client: ArchipelagoClient = Depends(get_ap_client),
) -> HealthResponse:
    return HealthResponse(
        status="ok",
        wsConnected=ap_client.ws_connected,
        sessionId=ap_client._config.session_id,
    )


@router.get("/room")
async def get_room(
    ap_client: ArchipelagoClient = Depends(get_ap_client),
) -> dict[str, Any]:
    return ap_client.get_room_dict()


@router.get("/slots")
async def get_slots(
    ap_client: ArchipelagoClient = Depends(get_ap_client),
) -> dict[str, Any]:
    return {"slots": ap_client.get_slots_summary()}


@router.get("/state")
async def get_state(
    state: StateManager = Depends(get_bridge_state),
) -> dict[str, Any]:
    state.merge_state_from_save()
    return state.to_api_dict()


@router.post("/commands", response_model=OkResponse)
async def post_command(
    body: CommandRequest,
    ap_client: ArchipelagoClient = Depends(get_ap_client),
) -> OkResponse:
    if not ap_client.ws_connected:
        raise HTTPException(status_code=503, detail="ws_disconnected")
    await ap_client.send_command(body.command)
    return OkResponse()


# ---------------------------------------------------------------------------
# Route handlers — authenticated
# ---------------------------------------------------------------------------

@router.post("/pause", response_model=OkResponse, dependencies=[Depends(require_auth)])
async def post_pause(
    ap_client: ArchipelagoClient = Depends(get_ap_client),
    coordinator: PauseResumeCoordinator = Depends(get_coordinator),
    broadcast: BroadcastFn | None = Depends(get_broadcast),
) -> OkResponse:
    asyncio.create_task(_pause_flow(ap_client, coordinator, broadcast or _noop_broadcast))
    return OkResponse()


@router.post("/resume", response_model=OkResponse, dependencies=[Depends(require_auth)])
async def post_resume(
    body: ResumeRequest | None = None,
    ap_client: ArchipelagoClient = Depends(get_ap_client),
    coordinator: PauseResumeCoordinator = Depends(get_coordinator),
    broadcast: BroadcastFn | None = Depends(get_broadcast),
) -> OkResponse:
    save_key = body.saveKey if body else None
    asyncio.create_task(_resume_flow(ap_client, save_key, coordinator, broadcast or _noop_broadcast))
    return OkResponse()


@router.post("/deathlink", response_model=OkResponse, dependencies=[Depends(require_auth)])
async def post_deathlink(
    body: DeathLinkRequest | None = None,
    ap_client: ArchipelagoClient = Depends(get_ap_client),
) -> OkResponse:
    if not ap_client.ws_connected:
        raise HTTPException(status_code=503, detail="ws_disconnected")
    source = body.source if body else ""
    cause = body.cause if body else None
    data: dict[str, Any] = {"source": source, "time": datetime.now(_timezone.utc).timestamp()}
    if cause:
        data["cause"] = cause
    await ap_client.send_packet({"cmd": "Bounce", "tags": ["DeathLink"], "data": data})
    return OkResponse()
