from __future__ import annotations

import asyncio
import json
import logging
from datetime import datetime, timezone

import aiohttp

from config import Config
from mercure import MercurePublisher
from reachable import _compute_reachable
from state import StateManager


def setup_logging(run_id: str) -> None:
    class _JsonFormatter(logging.Formatter):
        def format(self, record: logging.LogRecord) -> str:
            return json.dumps({
                "event": record.getMessage(),
                "run_id": run_id,
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
    publisher: MercurePublisher,
    config: Config,
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

        changed = False
        for slot_id in to_sweep:
            result, _ = await _compute_reachable(slot_id, state, semaphore, log)
            if result is not None:
                ps = state._states.get(slot_id)
                if ps:
                    ps.reachable_now = result.get("counts", {}).get("reachable_now", 0)
                    last_computed[slot_id] = (ps.checks_done, ps.items_received)
                    changed = True
                if not result.get("cached"):
                    await publisher.publish(
                        f"runs/{config.run_id}/slots/{slot_id}/reachable",
                        result,
                    )
            await asyncio.sleep(0)

        if changed:
            await publisher.publish(
                f"runs/{config.run_id}/players",
                state.to_api_dict(),
            )

        try:
            await asyncio.wait_for(recompute_event.wait(), timeout=30.0)
        except asyncio.TimeoutError:
            pass
        recompute_event.clear()


async def _apsave_reconcile_loop(
    state: StateManager,
    publisher: MercurePublisher,
    config: Config,
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

            changed_slots = [
                slot for slot, counts in after.items()
                if before.get(slot) != counts
            ]
            if changed_slots:
                log.info("apsave reconcile: state updated for slots %s - triggering recompute", changed_slots)
                recompute_event.set()
                await publisher.publish(f"runs/{config.run_id}/players", state.to_api_dict())
        except Exception as exc:
            log.warning("apsave reconcile error: %s", exc)


async def _heartbeat_loop(config: Config, http: aiohttp.ClientSession) -> None:
    log = logging.getLogger(__name__)
    url = (
        f"{config.symfony_internal_url}"
        f"/api/v1/internal/sessions/{config.run_id}/heartbeat"
    )
    while True:
        await asyncio.sleep(30)
        try:
            async with http.post(
                url, headers={"X-Internal-Secret": config.central_api_secret}
            ) as resp:
                if resp.status >= 400:
                    log.warning("heartbeat failed %d", resp.status)
        except Exception as exc:
            log.warning("heartbeat error: %s", exc)
