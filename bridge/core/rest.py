from __future__ import annotations

import asyncio
import glob as _glob
import json
import logging
import os as _os
import signal as _signal
import shlex as _shlex
from datetime import datetime, timezone as _timezone
from typing import Any

from aiohttp import web

from ap_client import ArchipelagoClient
from reachable import _compute_reachable, _reachable_cache
from state import StateManager
from wake_on_connect import WakeOnConnectServer

# Module-level wake-on-connect state (one listener at a time per bridge instance)
_wake_stop_event: asyncio.Event | None = None
_wake_task: "asyncio.Task[None] | None" = None


# ---------------------------------------------------------------------------
# Pause flow helpers (called by POST /pause background task)
# ---------------------------------------------------------------------------

async def _poll_for_save(ap_client: ArchipelagoClient) -> str | None:
    """Send /save to AP, then poll save_dir for an .apsave file (30s timeout)."""
    log = logging.getLogger("bridge.pause")
    config = ap_client._config
    save_dir = config.save_dir

    if ap_client.ws_connected:
        await ap_client.send_command("/save")
        await asyncio.sleep(2)  # give AP a moment to write the file

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
    log = logging.getLogger("bridge.pause")
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
    log = logging.getLogger("bridge.pause")
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

    # Wait up to 5 s for the process to exit, then SIGKILL
    for _ in range(25):
        await asyncio.sleep(0.2)
        try:
            _os.kill(pid, 0)
        except ProcessLookupError:
            log.info("pause: AP process exited cleanly pid=%d", pid)
            return

    try:
        _os.kill(pid, _signal.SIGKILL)
        log.warning("pause: sent SIGKILL to AP pid=%d (SIGTERM ignored)", pid)
    except ProcessLookupError:
        pass


async def _notify_paused(ap_client: ArchipelagoClient, save_key: str | None, failed_save: bool) -> None:
    """POST /sessions/{run_id}/paused callback to Symfony."""
    log = logging.getLogger("bridge.pause")
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
    log = logging.getLogger("bridge.wake")
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
    log = logging.getLogger("bridge.wake")
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
    log = logging.getLogger("bridge.wake")
    log.info("wake: on_connect triggered run_id=%s", ap_client._config.run_id)

    # Step 1: Notify Symfony idle → restarting
    if not await _notify_restarting(ap_client):
        log.error("wake: Symfony refused restarting transition, restart aborted")
        return

    # Step 2: Find save on disk
    save_path = await _find_local_save(ap_client._config.save_dir)
    if save_path is None:
        log.error("wake: no local save found, restart aborted")
        await _notify_restart_failed(ap_client)
        return

    # Step 3: Launch AP
    process = await _launch_ap(ap_client, save_path)
    if process is None:
        log.error("wake: AP launch failed")
        await _notify_restart_failed(ap_client)
        return

    # Step 4: Health-check
    healthy = await _wait_for_ap_health(ap_client)
    if not healthy:
        log.error("wake: AP did not become healthy")
        await _notify_restart_failed(ap_client)
        if process.returncode is None:
            process.kill()
        return

    # Step 5: Notify Symfony restarting → running
    await _notify_restarted(ap_client)
    log.info("wake: restart complete")


async def _pause_flow(ap_client: ArchipelagoClient) -> None:
    """Full pause flow: save, upload, kill AP, start TCP listener, notify Symfony."""
    global _wake_stop_event, _wake_task  # noqa: PLW0603

    log = logging.getLogger("bridge.pause")
    log.info("pause: flow started run_id=%s", ap_client._config.run_id)

    save_path = await _poll_for_save(ap_client)
    failed_save = save_path is None

    save_key = await _upload_save(ap_client, save_path) if save_path else None

    await _kill_ap(ap_client._config.ap_pid_file)

    # Start wake-on-connect TCP listener
    try:
        ap_port = int(ap_client._config.archipelago_ws_url.rsplit(":", 1)[-1])
        _wake_stop_event = asyncio.Event()
        server = WakeOnConnectServer(
            ap_port,
            _wake_stop_event,
            lambda: _wake_on_connect_flow(ap_client),
        )
        _wake_task = asyncio.create_task(server.serve())
        log.info("pause: wake-on-connect listener task started")
    except (ValueError, IndexError) as exc:
        log.error("pause: cannot parse AP port from %s: %s", ap_client._config.archipelago_ws_url, exc)

    await _notify_paused(ap_client, save_key, failed_save)
    log.info("pause: flow complete save_key=%s failed=%s", save_key, failed_save)


# ---------------------------------------------------------------------------
# Resume flow helpers (called by POST /resume background task)
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
    log = logging.getLogger("bridge.resume")
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
    log = logging.getLogger("bridge.resume")
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
    log = logging.getLogger("bridge.resume")
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
    log = logging.getLogger("bridge.resume")
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


async def _cancel_wake_task() -> None:
    """Cancel the running wake-on-connect listener, if any."""
    global _wake_stop_event, _wake_task  # noqa: PLW0603

    log = logging.getLogger("bridge.resume")
    if _wake_stop_event is not None:
        _wake_stop_event.set()
    if _wake_task is not None and not _wake_task.done():
        log.info("resume: cancelling wake-on-connect listener")
        try:
            await asyncio.wait_for(asyncio.shield(_wake_task), timeout=3.0)
        except (asyncio.TimeoutError, asyncio.CancelledError):
            _wake_task.cancel()
    _wake_task = None
    _wake_stop_event = None


async def _resume_flow(ap_client: ArchipelagoClient, last_save_key: str | None) -> None:
    """Full resume flow: cancel wake listener, find save, launch AP, health-check, notify Symfony."""
    log = logging.getLogger("bridge.resume")
    log.info("resume: flow started run_id=%s", ap_client._config.run_id)

    # Cancel the TCP listener if running (AC 4 of story 17.5)
    await _cancel_wake_task()

    # Step 1: Locate the save file
    save_path = await _find_local_save(ap_client._config.save_dir)

    if save_path is None and last_save_key:
        save_path = await _download_save_from_minio(ap_client, last_save_key)

    if save_path is None:
        log.error("resume: no save file available, cannot restart AP")
        await _notify_restart_failed(ap_client)
        return

    # Step 2: Launch AP
    process = await _launch_ap(ap_client, save_path)
    if process is None:
        log.error("resume: AP launch failed, cannot restart")
        await _notify_restart_failed(ap_client)
        return

    # Step 3: Health-check loop
    healthy = await _wait_for_ap_health(ap_client)
    if not healthy:
        log.error("resume: AP did not become healthy, restart failed")
        await _notify_restart_failed(ap_client)
        if process.returncode is None:
            process.kill()
        return

    # Step 4: Notify Symfony
    await _notify_restarted(ap_client)
    log.info("resume: flow complete")


def create_app(
    state: StateManager,
    ap_client: ArchipelagoClient,
    reachable_semaphore: asyncio.Semaphore | None = None,
) -> web.Application:
    if reachable_semaphore is None:
        reachable_semaphore = asyncio.Semaphore(1)
    app = web.Application()
    log = logging.getLogger(__name__)

    async def health(_: web.Request) -> web.Response:
        return web.json_response({"status": "ok", "ws_connected": ap_client.ws_connected})

    async def get_state(_: web.Request) -> web.Response:
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
        if not ap_client.ws_connected:
            return web.json_response(
                {"error": "ws_disconnected", "message": "Le serveur Archipelago est déconnecté"},
                status=503,
            )
        await ap_client.send_command(command)
        return web.json_response({"ok": True})

    async def get_hints(request: web.Request) -> web.Response:
        try:
            slot = int(request.match_info["slot"])
        except (KeyError, ValueError):
            return web.json_response({"error": "invalid slot"}, status=400)

        state.merge_state_from_save()
        ap_client.resolve_slot_hint_names(slot)
        ps = state._states.get(slot)
        hints = state.get_hints(slot)
        return web.json_response({
            "slot": slot,
            "hints": [h.to_dict() for h in hints],
            "hints_used": ps.hints_used if ps else 0,
            "hint_points_available": ps.hint_points_available if ps else 0,
            "hint_cost": ps.hint_cost if ps else 10,
        })

    async def request_hint(request: web.Request) -> web.Response:
        try:
            slot = int(request.match_info["slot"])
        except (KeyError, ValueError):
            return web.json_response({"error": "invalid slot"}, status=400)

        try:
            body = await request.json()
        except Exception:
            return web.json_response({"error": "invalid_json"}, status=400)

        location_id = body.get("location_id")
        if not isinstance(location_id, int) or location_id <= 0:
            return web.json_response({"error": "location_id (int > 0) is required"}, status=400)

        free = bool(body.get("free", False))

        if not ap_client.ws_connected:
            return web.json_response(
                {"error": "ws_disconnected", "message": "Le serveur Archipelago est déconnecté"},
                status=503,
            )

        # create_as_hint: 2 = admin scout (no point cost); 1 = normal (costs points from bridge slot)
        create_as_hint = 2 if free else 1
        try:
            await ap_client.send_packet({
                "cmd": "LocationScouts",
                "locations": [location_id],
                "create_as_hint": create_as_hint,
            })
        except RuntimeError as exc:
            return web.json_response({"error": str(exc)}, status=503)

        # Paid hint: optimistically increment budget counter before AP confirms via PrintJSON.
        # Free hints must not touch hints_used - the apsave won't reflect a cost for them.
        if not free:
            ps = state.ensure_slot(slot)
            ps.hints_used = max(0, ps.hints_used + 1)
            await ap_client._publish_hints(slot)

        log.info("hint requested: slot=%d location_id=%d free=%s", slot, location_id, free)
        return web.json_response({"ok": True, "slot": slot, "location_id": location_id, "free": free})

    async def get_reachable(request: web.Request) -> web.Response:
        try:
            slot = int(request.match_info["slot"])
        except (KeyError, ValueError):
            return web.json_response({"error": "invalid slot"}, status=400)

        state.merge_state_from_save()
        result, err_msg = await _compute_reachable(slot, state, reachable_semaphore, log)

        if result is None:
            return web.json_response(
                {"error": err_msg},
                status=500 if "timed out" not in err_msg else 504,
            )

        ps = state._states.get(slot)
        if ps is not None:
            # Bridge slot_name (from AP Connected packet) is authoritative over the
            # reachability subprocess name (which may be the YAML file name, not the player alias).
            if ps.slot_name:
                result["player"] = ps.slot_name
            new_reachable = result.get("counts", {}).get("reachable_now", 0)
            if ps.reachable_now != new_reachable:
                ps.reachable_now = new_reachable
                await ap_client._publish_players()

        return web.json_response(result)

    async def get_item_locations(request: web.Request) -> web.Response:
        try:
            slot = int(request.match_info["slot"])
        except (KeyError, ValueError):
            return web.json_response({"error": "invalid slot"}, status=400)

        state.merge_state_from_save()

        # Ensure reachability is computed for every known slot so locations across
        # all games are visible, not just slots that previously called /reachable/{slot}.
        for s in list(state._states.keys()):
            if s not in _reachable_cache:
                await _compute_reachable(s, state, reachable_semaphore, log)

        _CHECK_STATUS: dict[str, str] = {
            "reachable_unchecked": "reachable",
            "reachable_checked":   "checked",
            "unreachable_unchecked": "blocked",
            "checked_unreachable": "checked",
        }

        locations = []
        for sender_slot, (_, result) in _reachable_cache.items():
            sender_name = ap_client._store.resolve_player(sender_slot)
            for list_name, check_status in _CHECK_STATUS.items():
                for check in result.get(list_name, []):
                    item = check.get("item")
                    if not item or item.get("slot") != slot:
                        continue
                    locations.append({
                        "item_id": item["id"],
                        "item_name": item["name"],
                        "location_id": check["id"],
                        "location_name": check["name"],
                        "finding_player": sender_slot,
                        "finding_player_name": sender_name,
                        "check_status": check_status,
                    })

        return web.json_response({"slot": slot, "locations": locations})

    async def post_save(_: web.Request) -> web.Response:
        save_dir = ap_client._config.save_dir
        save_files = _glob.glob(_os.path.join(save_dir, "*.apsave"))
        saved = len(save_files) > 0
        log.info("save requested: saved=%s save_dir=%s", saved, save_dir)
        return web.json_response({"saved": saved})

    async def post_pause(request: web.Request) -> web.Response:
        auth = request.headers.get("Authorization", "")
        token = ap_client._config.bridge_internal_token
        if not token or auth != f"Bearer {token}":
            return web.json_response({"error": "unauthorized"}, status=401)
        asyncio.create_task(_pause_flow(ap_client))
        return web.json_response({"ok": True})

    async def post_resume(request: web.Request) -> web.Response:
        auth = request.headers.get("Authorization", "")
        token = ap_client._config.bridge_internal_token
        if not token or auth != f"Bearer {token}":
            return web.json_response({"error": "unauthorized"}, status=401)
        try:
            body = await request.json()
        except Exception:
            body = {}
        last_save_key = body.get("lastSaveKey") if isinstance(body, dict) else None
        if not isinstance(last_save_key, str):
            last_save_key = None
        asyncio.create_task(_resume_flow(ap_client, last_save_key))
        return web.json_response({"ok": True})

    app.router.add_get("/health", health)
    app.router.add_get("/state", get_state)
    app.router.add_post("/commands", post_command)
    app.router.add_post("/save", post_save)
    app.router.add_post("/pause", post_pause)
    app.router.add_post("/resume", post_resume)
    app.router.add_get("/hints/{slot}", get_hints)
    app.router.add_post("/hints/{slot}/request", request_hint)
    app.router.add_get("/reachable/{slot}", get_reachable)
    app.router.add_get("/item-locations/{slot}", get_item_locations)
    return app
