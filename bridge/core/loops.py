from __future__ import annotations

import asyncio
import json
import logging
from collections.abc import Awaitable, Callable
from datetime import datetime, timezone
from typing import Any

from .reachable import _compute_reachable
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


async def _reachable_sweep_loop(
    state: StateManager,
    broadcast: BroadcastFn,
    session_id: str,
    semaphore: asyncio.Semaphore,
    recompute_event: asyncio.Event,
) -> None:
    """Compute reachable_now for every non-goal slot on WS events or every 30s."""
    log = logging.getLogger(__name__)
    await asyncio.sleep(20)

    last_computed: dict[int, tuple[int, int]] = {}

    while True:
        to_sweep = [
            slot_id for slot_id, ps in list(state._states.items())
            if ps.client_status != 30
            and last_computed.get(slot_id) != (ps.checks_done, ps.items_received)
        ]

        if to_sweep:
            log.info("reachable sweep: %d slot(s) to compute", len(to_sweep))

        changed_slots: list[int] = []
        for slot_id in to_sweep:
            result, _ = await _compute_reachable(slot_id, state, semaphore, log)
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
) -> None:
    """Periodically reconcile in-memory state with the apsave (every 5s)."""
    log = logging.getLogger(__name__)
    while True:
        await asyncio.sleep(5)
        try:
            before = {slot: (ps.checks_done, ps.items_received) for slot, ps in state._states.items()}
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
