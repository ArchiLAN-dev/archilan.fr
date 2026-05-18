#!/usr/bin/env python3
"""Bridge.py - entry point and public API re-exporter for the bridge package."""
from __future__ import annotations

import asyncio
import logging
import os
import sys

import aiohttp
from aiohttp import web

if __package__ in {None, ""}:
    repo_root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    sys.path.insert(0, repo_root)

from bridge.core.config import Config
from bridge.core.domain import HintInfo, PlayerState
from bridge.core.save_parser import load_save_state
from bridge.core.state import StateManager
from bridge.core.mercure import MercurePublisher, TokenManager
from bridge.core.ap_client import ArchipelagoClient, DataPackageStore
from bridge.core.rest import create_app
from bridge.core.loops import (
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
    "create_app",
    "setup_logging",
]


_TOKEN_FETCH_DELAYS = (2, 4, 8, 16, 32)


async def _fetch_token_with_retry(token_mgr: TokenManager, log: logging.Logger) -> None:
    for attempt, delay in enumerate(_TOKEN_FETCH_DELAYS, start=1):
        try:
            await token_mgr.fetch_token()
            return
        except Exception as exc:
            log.warning(
                "Token fetch failed (attempt %d/%d): %s. Retrying in %ds…",
                attempt,
                len(_TOKEN_FETCH_DELAYS),
                exc,
                delay,
            )
            await asyncio.sleep(delay)
    await token_mgr.fetch_token()


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
        await _fetch_token_with_retry(token_mgr, log)
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
    if "--check-imports" in sys.argv:
        print("script import ok")
    else:
        asyncio.run(_main())
