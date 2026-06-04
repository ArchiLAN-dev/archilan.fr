from __future__ import annotations

import asyncio
import glob
import json
import logging
import os
from collections.abc import Awaitable, Callable
from datetime import datetime, timezone
from typing import Any

import httpx

from .reachable import _compute_reachable
from .save_parser import load_save_state_from_json
from .state import StateManager

BroadcastFn = Callable[[str, dict[str, Any]], Awaitable[None]]


def setup_logging(session_id: str) -> None:
    class _JsonFormatter(logging.Formatter):
        def format(self, record: logging.LogRecord) -> str:
            return json.dumps({
                "event": record.getMessage(),
                "session_id": session_id,
                "timestamp": datetime.fromtimestamp(record.created, tz=timezone.utc).isoformat(),
                "severity": record.levelname,
            })

    handler = logging.StreamHandler()
    handler.setFormatter(_JsonFormatter())
    root = logging.getLogger()
    root.handlers = [handler]
    root.setLevel(logging.INFO)


async def _push_reachable_to_api(
    session_id: str,
    slot_id: int,
    result: dict[str, Any],
    central_api_url: str,
    central_api_secret: str,
    log: logging.Logger,
) -> None:
    """Push a full reachability result to Symfony so it can publish to Mercure."""
    if not central_api_url or not central_api_secret:
        return
    url = (
        f"{central_api_url.rstrip('/')}"
        f"/api/v1/internal/sessions/{session_id}/slots/{slot_id}/reachable-push"
    )
    headers = {"X-Internal-Secret": central_api_secret}
    try:
        async with httpx.AsyncClient(timeout=10) as client:
            resp = await client.post(url, json=result, headers=headers)
            if resp.status_code not in (200, 204):
                log.warning("reachable push: unexpected status %d for slot %d", resp.status_code, slot_id)
    except Exception as exc:
        log.warning("reachable push error slot %d: %s", slot_id, exc)


async def _reachable_sweep_loop(
    state: StateManager,
    broadcast: BroadcastFn,
    session_id: str,
    semaphore: asyncio.Semaphore,
    recompute_event: asyncio.Event,
    runtime: Any = None,
    central_api_url: str = "",
    central_api_secret: str = "",
) -> None:
    """Compute reachable_now for every non-goal slot on WS events or every 30s."""
    log = logging.getLogger(__name__)
    await asyncio.sleep(20)

    last_computed: dict[int, tuple[int, int]] = {}

    while True:
        to_sweep = [
            slot_id for slot_id, ps in list(state._states.items())
            if last_computed.get(slot_id) != (ps.checks_done, ps.items_received)
        ]

        if to_sweep:
            log.info("reachable sweep: %d slot(s) to compute", len(to_sweep))

        changed_slots: list[int] = []
        for slot_id in to_sweep:
            result, _ = await _compute_reachable(slot_id, state, semaphore, log, runtime=runtime)
            if result is not None:
                ps = state._states.get(slot_id)
                if ps:
                    ps.reachable_now = result.get("counts", {}).get("reachable_now", 0)
                    last_computed[slot_id] = (ps.checks_done, ps.items_received)
                    changed_slots.append(slot_id)
                if not result.get("cached"):
                    await broadcast("reachable_changed", {
                        "sessionId": session_id,
                        "slot": slot_id,
                        "reachableNow": ps.reachable_now if ps else 0,
                    })
                    await _push_reachable_to_api(
                        session_id, slot_id, result,
                        central_api_url, central_api_secret, log,
                    )
            await asyncio.sleep(0)

        if changed_slots:
            # Import here to avoid circular; slot summaries are built by ap_client
            # We reuse _broadcast_state_changed via a lightweight slots update
            pass  # state_changed will be broadcast by ap_client on next event

        try:
            await asyncio.wait_for(recompute_event.wait(), timeout=30.0)
        except asyncio.TimeoutError:
            pass
        recompute_event.clear()


async def _apsave_reconcile_loop(
    state: StateManager,
    broadcast: BroadcastFn,
    session_id: str,
    recompute_event: asyncio.Event,
    runtime: Any = None,
) -> None:
    """Periodically reconcile in-memory state with the apsave (every 5s)."""
    log = logging.getLogger(__name__)
    while True:
        await asyncio.sleep(5)
        try:
            before = {slot: (ps.checks_done, ps.items_received) for slot, ps in state._states.items()}

            if runtime is not None and hasattr(runtime, "run_save_parse") and state._save_dir:
                files = glob.glob(f"{state._save_dir}/*.apsave")
                if files:
                    latest_mtime = max(os.path.getmtime(f) for f in files)
                    if latest_mtime > state._save_mtime:
                        try:
                            output = await asyncio.wait_for(
                                runtime.run_save_parse(save_dir=state._save_dir),
                                timeout=60.0,
                            )
                            saved = load_save_state_from_json(output)
                            state.apply_saved_states(saved)
                            state._save_mtime = latest_mtime
                        except asyncio.TimeoutError:
                            log.warning("apsave reconcile: Docker parse timed out")
                        except Exception as exc:
                            log.warning("apsave reconcile: Docker parse failed: %s - fallback", exc)
                            state.merge_state_from_save()
            else:
                state.merge_state_from_save()

            after = {slot: (ps.checks_done, ps.items_received) for slot, ps in state._states.items()}
            changed_slots = [slot for slot, counts in after.items() if before.get(slot) != counts]
            if changed_slots:
                log.info("apsave reconcile: state updated for slots %s", changed_slots)
                recompute_event.set()
        except Exception as exc:
            log.warning("apsave reconcile error: %s", exc)


async def _ws_heartbeat_loop(broadcast: BroadcastFn, session_id: str) -> None:
    """Send a heartbeat WS notification every 30 s."""
    log = logging.getLogger(__name__)
    while True:
        await asyncio.sleep(30)
        try:
            await broadcast("heartbeat", {"sessionId": session_id, "wsConnected": True})
        except Exception as exc:
            log.warning("heartbeat broadcast error: %s", exc)


async def _api_heartbeat_loop(session_id: str, central_api_url: str, central_api_secret: str) -> None:
    """Call the Symfony heartbeat endpoint every 30 s to keep lastHeartbeatAt fresh."""
    if not central_api_url or not central_api_secret:
        return
    log = logging.getLogger(__name__)
    url = f"{central_api_url.rstrip('/')}/api/v1/internal/sessions/{session_id}/heartbeat"
    headers = {"X-Internal-Secret": central_api_secret}
    async with httpx.AsyncClient(timeout=10) as client:
        while True:
            await asyncio.sleep(30)
            try:
                resp = await client.post(url, headers=headers)
                if resp.status_code not in (200, 204):
                    log.warning("api heartbeat: unexpected status %d", resp.status_code)
            except Exception as exc:
                log.warning("api heartbeat error: %s", exc)
