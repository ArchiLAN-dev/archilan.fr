#!/usr/bin/env python3
"""Bridge.py - entry point and public API re-exporter for the bridge package."""
from __future__ import annotations

import asyncio
import logging
import os
import sys

import aiohttp
from aiohttp import web

# Bootstrap: add core/ to sys.path so all core modules can import siblings directly.
# Works in both script mode (python /bridge/bridge.py) and package mode (from bridge.bridge import ...).
_core = os.path.join(os.path.dirname(os.path.abspath(__file__)), "core")
if _core not in sys.path:
    sys.path.insert(0, _core)

# Re-export all public symbols so tests using `from bridge.bridge import X` keep working.
from config import Config  # noqa: E402
from domain import HintInfo, PlayerState  # noqa: E402
from save_parser import load_save_state  # noqa: E402
from state import StateManager  # noqa: E402
from mercure import MercurePublisher, TokenManager  # noqa: E402
from ap_client import (  # noqa: E402
    ArchipelagoClient,
    DataPackageStore,
    _build_feed_event,
    _PRINT_TYPE_MAP,
    _WS_RETRY_DELAYS,
)
from reachable import (  # noqa: E402
    _compute_reachable,
    _daemon_ready_events,
    _reachable_cache,
    _reachable_daemons,
    _start_daemon,
)
from rest import create_app  # noqa: E402
from loops import (  # noqa: E402
    _apsave_reconcile_loop,
    _heartbeat_loop,
    _reachable_sweep_loop,
    setup_logging,
)

__all__ = [
    "Config",
    "HintInfo",
    "PlayerState",
    "load_save_state",
    "StateManager",
    "MercurePublisher",
    "TokenManager",
    "ArchipelagoClient",
    "DataPackageStore",
    "_build_feed_event",
    "_compute_reachable",
    "create_app",
    "setup_logging",
]


async def _main() -> None:
    config = Config.from_env()
    setup_logging(config.run_id)
    log = logging.getLogger(__name__)

    initial_state = load_save_state(config.save_dir)
    goals_file = os.path.join(config.save_dir, "bridge_goals.json")
    state = StateManager(initial_state, goals_file=goals_file, save_dir=config.save_dir)

    timeout = aiohttp.ClientTimeout(total=10)
    async with aiohttp.ClientSession(timeout=timeout) as http:
        token_mgr = TokenManager(config, http)
        await token_mgr.fetch_token()
        token_mgr.schedule_refresh(config.token_refresh_interval)

        publisher = MercurePublisher(config, token_mgr, http)
        recompute_event = asyncio.Event()
        ap_client = ArchipelagoClient(config, state, publisher, recompute_event, http)

        reachable_semaphore = asyncio.Semaphore(1)
        app = create_app(state, ap_client, reachable_semaphore)
        runner = web.AppRunner(app)
        await runner.setup()
        site = web.TCPSite(runner, "0.0.0.0", config.rest_port)
        await site.start()
        log.info("REST API listening on port %d", config.rest_port)

        _heartbeat_task = asyncio.create_task(_heartbeat_loop(config, http))
        _sweep_task = asyncio.create_task(
            _reachable_sweep_loop(state, publisher, config, reachable_semaphore, recompute_event)
        )
        _reconcile_task = asyncio.create_task(
            _apsave_reconcile_loop(state, publisher, config, recompute_event)
        )

        try:
            await ap_client.run_with_reconnect()
        finally:
            _heartbeat_task.cancel()
            _sweep_task.cancel()
            _reconcile_task.cancel()


if __name__ == "__main__":
    asyncio.run(_main())
