from __future__ import annotations

import asyncio
import glob as _glob
import json
import logging
import os as _os
import signal as _signal
import shlex as _shlex
from datetime import datetime, timezone as _timezone

from aiohttp import web

from .ap_client import ArchipelagoClient
from .coordinator import PauseResumeCoordinator
from .rest_keys import APP_AP_CLIENT, APP_COORDINATOR, APP_STATE
from .state import StateManager
from .wake_on_connect import WakeOnConnectServer

log = logging.getLogger("bridge.rest_session")


# ---------------------------------------------------------------------------
# Auth helper
# ---------------------------------------------------------------------------

def _require_internal_auth(request: web.Request, token: str) -> web.Response | None:
    auth = request.headers.get("Authorization", "")
    if not token or auth != f"Bearer {token}":
        return web.json_response({"error": "unauthorized"}, status=401)
    return None


# ---------------------------------------------------------------------------
# Pause flow helpers
# ---------------------------------------------------------------------------

async def _poll_for_save(ap_client: ArchipelagoClient) -> str | None:
    """Send /save to AP, then poll save_dir for an .apsave file (30s timeout)."""
    config = ap_client._config
    save_dir = config.save_dir

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
    """Upload save file to MinIO; returns the object key or None on failure."""
    config = ap_client._config

    if not all([config.minio_endpoint, config.minio_access_key, config.minio_secret_key]):
        log.warning("pause: MinIO not configured, skipping upload")
        return None

    timestamp = datetime.now(_timezone.utc).strftime("%Y%m%d%H%M%S")
    key = f"sessions/{config.run_id}/saves/{timestamp}.apsave"

    try:
        with open(save_path, "rb") as fh:
            body = fh.read()
        loop = asyncio.get_event_loop()
        await loop.run_in_executor(
            None,
            _boto3_upload,
            config.minio_endpoint,
            config.minio_access_key,
            config.minio_secret_key,
            config.minio_bucket,
            key,
            body,
        )
        log.info("pause: uploaded save to MinIO key=%s", key)
        return key
    except Exception as exc:
        log.error("pause: MinIO upload failed: %s", exc)
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


async def _notify_paused(ap_client: ArchipelagoClient, save_key: str | None, failed_save: bool) -> None:
    """POST /sessions/{run_id}/paused callback to Symfony."""
    config = ap_client._config
    if ap_client._http is None:
        log.warning("pause: no HTTP session, cannot notify Symfony")
        return

    url = f"{config.symfony_internal_url}/api/v1/sessions/{config.run_id}/paused"
    try:
        async with ap_client._http.post(
            url,
            json={"saveKey": save_key, "failedSave": failed_save},
            headers={"Authorization": f"Bearer {config.bridge_internal_token}"},
        ) as resp:
            if resp.status >= 400:
                log.warning("pause: Symfony callback returned %d", resp.status)
            else:
                log.info("pause: Symfony notified save_key=%s failed=%s", save_key, failed_save)
    except Exception as exc:
        log.error("pause: Symfony callback error: %s", exc)


async def _notify_restarting(ap_client: ArchipelagoClient) -> bool:
    """POST /internal/sessions/{run_id}/restarting - bridge-triggered wake-on-connect."""
    config = ap_client._config
    if ap_client._http is None:
        log.warning("wake: no HTTP session, cannot call /restarting")
        return False
    url = f"{config.symfony_internal_url}/api/v1/internal/sessions/{config.run_id}/restarting"
    try:
        async with ap_client._http.post(
            url,
            json={},
            headers={"Authorization": f"Bearer {config.bridge_internal_token}"},
        ) as resp:
            if resp.status >= 400:
                log.warning("wake: /restarting callback returned %d", resp.status)
                return False
            else:
                log.info("wake: Symfony notified - session is restarting")
                return True
    except Exception as exc:
        log.error("wake: /restarting callback error: %s", exc)
        return False


async def _notify_restart_failed(ap_client: ArchipelagoClient) -> None:
    """POST /internal/sessions/{run_id}/restart-failed - AP launch or health-check failed."""
    config = ap_client._config
    if ap_client._http is None:
        log.warning("wake: no HTTP session, cannot call /restart-failed")
        return
    url = f"{config.symfony_internal_url}/api/v1/internal/sessions/{config.run_id}/restart-failed"
    try:
        async with ap_client._http.post(
            url,
            json={},
            headers={"Authorization": f"Bearer {config.bridge_internal_token}"},
        ) as resp:
            if resp.status >= 400:
                log.warning("wake: /restart-failed callback returned %d", resp.status)
            else:
                log.info("wake: Symfony notified - restart failed")
    except Exception as exc:
        log.error("wake: /restart-failed callback error: %s", exc)


async def _wake_on_connect_flow(ap_client: ArchipelagoClient) -> None:
    """Full restart flow triggered by a player TCP connection (wake-on-connect)."""
    log.info("wake: on_connect triggered run_id=%s", ap_client._config.run_id)

    if not await _notify_restarting(ap_client):
        log.error("wake: Symfony refused restarting transition, restart aborted")
        return

    save_path = await _find_local_save(ap_client._config.save_dir)
    if save_path is None:
        log.error("wake: no local save found, restart aborted")
        await _notify_restart_failed(ap_client)
        return

    process = await _launch_ap(ap_client, save_path)
    if process is None:
        log.error("wake: AP launch failed")
        await _notify_restart_failed(ap_client)
        return

    healthy = await _wait_for_ap_health(ap_client)
    if not healthy:
        log.error("wake: AP did not become healthy")
        await _notify_restart_failed(ap_client)
        if process.returncode is None:
            process.kill()
        return

    await _notify_restarted(ap_client)
    log.info("wake: restart complete")


async def _pause_flow(ap_client: ArchipelagoClient, coordinator: PauseResumeCoordinator) -> None:
    """Full pause flow: save, upload, kill AP, start TCP listener, notify Symfony."""
    log.info("pause: flow started run_id=%s", ap_client._config.run_id)

    save_path = await _poll_for_save(ap_client)
    failed_save = save_path is None

    save_key = await _upload_save(ap_client, save_path) if save_path else None

    await _kill_ap(ap_client._config.ap_pid_file)

    try:
        ap_port = int(ap_client._config.archipelago_ws_url.rsplit(":", 1)[-1])
        coordinator.wake_stop_event = asyncio.Event()
        server = WakeOnConnectServer(
            ap_port,
            coordinator.wake_stop_event,
            lambda: _wake_on_connect_flow(ap_client),
        )
        coordinator.wake_task = asyncio.create_task(server.serve())
        log.info("pause: wake-on-connect listener task started")
    except (ValueError, IndexError) as exc:
        log.error("pause: cannot parse AP port from %s: %s", ap_client._config.archipelago_ws_url, exc)

    await _notify_paused(ap_client, save_key, failed_save)
    log.info("pause: flow complete save_key=%s failed=%s", save_key, failed_save)


# ---------------------------------------------------------------------------
# Resume flow helpers
# ---------------------------------------------------------------------------

async def _find_local_save(save_dir: str) -> str | None:
    """Return the path of the most recently modified .apsave in save_dir, or None."""
    files = sorted(
        _glob.glob(_os.path.join(save_dir, "*.apsave")),
        key=_os.path.getmtime,
    )
    return files[-1] if files else None


async def _download_save_from_minio(ap_client: ArchipelagoClient, save_key: str) -> str | None:
    """Download save from MinIO into save_dir and return the local path."""
    config = ap_client._config
    if not all([config.minio_endpoint, config.minio_access_key, config.minio_secret_key]):
        log.warning("resume: MinIO not configured, cannot download save")
        return None

    dest_path = _os.path.join(config.save_dir, _os.path.basename(save_key))
    try:
        loop = asyncio.get_event_loop()
        contents = await loop.run_in_executor(
            None,
            _boto3_download,
            config.minio_endpoint,
            config.minio_access_key,
            config.minio_secret_key,
            config.minio_bucket,
            save_key,
        )
        with open(dest_path, "wb") as fh:
            fh.write(contents)
        log.info("resume: downloaded save from MinIO key=%s", save_key)
        return dest_path
    except Exception as exc:
        log.error("resume: MinIO download failed: %s", exc)
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
    """Launch the AP process with the given save file; returns the Process or None on error."""
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
    config = ap_client._config

    try:
        ap_port = int(config.archipelago_ws_url.rsplit(":", 1)[-1])
    except (ValueError, IndexError):
        log.error("resume: cannot parse AP port from %s", config.archipelago_ws_url)
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


async def _notify_restarted(ap_client: ArchipelagoClient) -> None:
    """POST /sessions/{run_id}/restarted - send empty connection details so Symfony preserves existing."""
    config = ap_client._config
    if ap_client._http is None:
        log.warning("resume: no HTTP session, cannot notify Symfony")
        return

    url = f"{config.symfony_internal_url}/api/v1/sessions/{config.run_id}/restarted"
    try:
        async with ap_client._http.post(
            url,
            json={"connectionHost": "", "connectionPort": 0, "bridgePort": 0},
            headers={"Authorization": f"Bearer {config.bridge_internal_token}"},
        ) as resp:
            if resp.status >= 400:
                log.warning("resume: Symfony /restarted callback returned %d", resp.status)
            else:
                log.info("resume: Symfony notified - session is running")
    except Exception as exc:
        log.error("resume: Symfony /restarted callback error: %s", exc)


async def _cancel_wake_task(coordinator: PauseResumeCoordinator) -> None:
    """Cancel the running wake-on-connect listener, if any."""
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
) -> None:
    """Full resume flow: cancel wake listener, find save, launch AP, health-check, notify Symfony."""
    log.info("resume: flow started run_id=%s", ap_client._config.run_id)

    await _cancel_wake_task(coordinator)

    save_path = await _find_local_save(ap_client._config.save_dir)

    if save_path is None and last_save_key:
        save_path = await _download_save_from_minio(ap_client, last_save_key)

    if save_path is None:
        log.error("resume: no save file available, cannot restart AP")
        await _notify_restart_failed(ap_client)
        return

    process = await _launch_ap(ap_client, save_path)
    if process is None:
        log.error("resume: AP launch failed, cannot restart")
        await _notify_restart_failed(ap_client)
        return

    healthy = await _wait_for_ap_health(ap_client)
    if not healthy:
        log.error("resume: AP did not become healthy, restart failed")
        await _notify_restart_failed(ap_client)
        if process.returncode is None:
            process.kill()
        return

    await _notify_restarted(ap_client)
    log.info("resume: flow complete")


# ---------------------------------------------------------------------------
# Route handlers
# ---------------------------------------------------------------------------

async def health(request: web.Request) -> web.Response:
    ap_client: ArchipelagoClient = request.app[APP_AP_CLIENT]
    return web.json_response({"status": "ok", "ws_connected": ap_client.ws_connected})


async def get_state(request: web.Request) -> web.Response:
    state: StateManager = request.app[APP_STATE]
    state.merge_state_from_save()
    return web.json_response(state.to_api_dict())


async def post_command(request: web.Request) -> web.Response:
    try:
        body = await request.json()
    except (json.JSONDecodeError, Exception):
        return web.json_response({"error": "invalid_json"}, status=400)
    command = body.get("command")
    if not isinstance(command, str) or not command.strip():
        return web.json_response({"error": "command is required"}, status=400)
    ap_client: ArchipelagoClient = request.app[APP_AP_CLIENT]
    if not ap_client.ws_connected:
        return web.json_response(
            {"error": "ws_disconnected", "message": "Le serveur Archipelago est déconnecté"},
            status=503,
        )
    await ap_client.send_command(command)
    return web.json_response({"ok": True})


async def post_save(request: web.Request) -> web.Response:
    ap_client: ArchipelagoClient = request.app[APP_AP_CLIENT]
    save_dir = ap_client._config.save_dir
    save_files = _glob.glob(_os.path.join(save_dir, "*.apsave"))
    saved = len(save_files) > 0
    log.info("save requested: saved=%s save_dir=%s", saved, save_dir)
    return web.json_response({"saved": saved})


async def post_pause(request: web.Request) -> web.Response:
    ap_client: ArchipelagoClient = request.app[APP_AP_CLIENT]
    token = ap_client._config.bridge_internal_token
    err = _require_internal_auth(request, token)
    if err is not None:
        return err
    coordinator: PauseResumeCoordinator = request.app[APP_COORDINATOR]
    asyncio.create_task(_pause_flow(ap_client, coordinator))
    return web.json_response({"ok": True})


async def post_resume(request: web.Request) -> web.Response:
    ap_client: ArchipelagoClient = request.app[APP_AP_CLIENT]
    token = ap_client._config.bridge_internal_token
    err = _require_internal_auth(request, token)
    if err is not None:
        return err
    try:
        body = await request.json()
    except Exception:
        body = {}
    last_save_key = body.get("lastSaveKey") if isinstance(body, dict) else None
    if not isinstance(last_save_key, str):
        last_save_key = None
    coordinator: PauseResumeCoordinator = request.app[APP_COORDINATOR]
    asyncio.create_task(_resume_flow(ap_client, last_save_key, coordinator))
    return web.json_response({"ok": True})
