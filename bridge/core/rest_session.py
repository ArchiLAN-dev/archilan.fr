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

from fastapi import APIRouter, Depends, Header, HTTPException, Query

from .ap_client import ArchipelagoClient
from .coordinator import PauseResumeCoordinator
from .deps import BroadcastFn, get_ap_client, get_bridge_state, get_broadcast, get_coordinator, require_auth
from .reachable import _reachable_cache
from .schemas import (
    CommandRequest,
    DeathLinkRequest,
    HealthResponse,
    LocationPlacementResponse,
    OkResponse,
    SlotDetailResponse,
    SphereResponse,
    SpheresResponse,
)
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
    """Full pause flow: save, kill AP, start TCP listener, broadcast lifecycle."""
    session_id = ap_client._config.session_id
    log.info("pause: flow started session_id=%s", session_id)

    save_path = await _poll_for_save(ap_client)
    failed_save = save_path is None

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
        "failedSave": failed_save,
    })
    log.info("pause: flow complete failed=%s", failed_save)


# ---------------------------------------------------------------------------
# Resume flow helpers
# ---------------------------------------------------------------------------

async def _find_local_save(save_dir: str) -> str | None:
    files = sorted(
        _glob.glob(_os.path.join(save_dir, "*.apsave")),
        key=_os.path.getmtime,
    )
    return files[-1] if files else None


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
    coordinator: PauseResumeCoordinator,
    broadcast: BroadcastFn,
) -> None:
    """Full resume flow: cancel wake listener, find save, launch AP, health-check, broadcast."""
    session_id = ap_client._config.session_id
    log.info("resume: flow started session_id=%s", session_id)

    await _cancel_wake_task(coordinator)

    save_path = await _find_local_save(ap_client._config.save_dir)

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
    x_ap_admin_password: str = Header(default=""),
) -> OkResponse:
    if not ap_client.ws_connected:
        raise HTTPException(status_code=503, detail="ws_disconnected")
    if x_ap_admin_password:
        await ap_client.send_command(f"!admin login {x_ap_admin_password}")
    await ap_client.send_command(body.command)
    return OkResponse()


@router.get("/feed")
async def get_feed(
    ap_client: ArchipelagoClient = Depends(get_ap_client),
    limit: int = Query(default=50, ge=1, le=200),
) -> dict[str, Any]:
    return {"events": ap_client.get_feed(limit)}


@router.get("/data-package")
async def get_data_package_index(
    ap_client: ArchipelagoClient = Depends(get_ap_client),
) -> dict[str, Any]:
    return {"games": ap_client.list_data_package_games()}


@router.get("/data-package/{game}")
async def get_data_package_game(
    game: str,
    ap_client: ArchipelagoClient = Depends(get_ap_client),
) -> dict[str, Any]:
    result = ap_client.get_data_package(game)
    if result is None:
        raise HTTPException(status_code=404, detail=f"game '{game}' not in data package")
    return result


@router.get("/slots/{slot}", response_model=SlotDetailResponse)
async def get_slot_detail(
    slot: int,
    ap_client: ArchipelagoClient = Depends(get_ap_client),
) -> SlotDetailResponse:
    detail = ap_client.get_slot_detail(slot)
    if detail is None:
        raise HTTPException(status_code=404, detail=f"slot {slot} not found")
    return SlotDetailResponse(**detail)


@router.get("/spheres", response_model=SpheresResponse, dependencies=[Depends(require_auth)])
async def get_spheres(
    ap_client: ArchipelagoClient = Depends(get_ap_client),
) -> SpheresResponse:
    if not _reachable_cache:
        return SpheresResponse(cached=False, spheres=[])

    sphere_map: dict[int, list[LocationPlacementResponse]] = {}
    for _slot, (_, result) in _reachable_cache.items():
        for sphere_data in result.get("spheres", []):
            idx = int(sphere_data.get("index", 0))
            if idx not in sphere_map:
                sphere_map[idx] = []
            for loc in sphere_data.get("locations", []):
                item = loc.get("item") or {}
                receiver_slot = int(item.get("slot", 0))
                sphere_map[idx].append(LocationPlacementResponse(
                    locationId=int(loc.get("id", 0)),
                    locationName=str(loc.get("name", "")),
                    itemId=int(item.get("id", 0)),
                    itemName=str(item.get("name", "")),
                    receivingSlot=receiver_slot,
                    receivingPlayerName=str(
                        item.get("slot_name")
                        or ap_client._store.resolve_player(receiver_slot)
                    ),
                ))

    return SpheresResponse(
        cached=True,
        spheres=[SphereResponse(index=i, locations=locs) for i, locs in sorted(sphere_map.items())],
    )


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
    ap_client: ArchipelagoClient = Depends(get_ap_client),
    coordinator: PauseResumeCoordinator = Depends(get_coordinator),
    broadcast: BroadcastFn | None = Depends(get_broadcast),
) -> OkResponse:
    asyncio.create_task(_resume_flow(ap_client, coordinator, broadcast or _noop_broadcast))
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
