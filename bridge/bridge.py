#!/usr/bin/env python3
"""Bridge entry point and public API re-exporter."""
from __future__ import annotations

import asyncio
import logging
import os
import sys

import uvicorn

if __package__ in {None, ""}:
    repo_root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    sys.path.insert(0, repo_root)

from bridge.adapters.docker_runtime import DockerRuntimeAdapter
from bridge.adapters.kubernetes_runtime import KubernetesRuntimeAdapter
from bridge.adapters.subprocess_runtime import SubprocessRuntimeAdapter
from bridge.ports.runtime import RuntimeAdapter
from bridge.core.ap_client import ArchipelagoClient, DataPackageStore
from bridge.core.config import Config
from bridge.core.coordinator import PauseResumeCoordinator
from bridge.core.domain import HintInfo, PlayerState
from bridge.core.loops import (
    _apsave_reconcile_loop,
    _reachable_sweep_loop,
    _ws_heartbeat_loop,
    setup_logging,
)
from bridge.core.rest import create_app
from bridge.core.rest_operator import OperatorState
from bridge.core.save_parser import load_save_state
from bridge.core.state import StateManager
from bridge.core.ws_server import WsServer

__all__ = [
    "Config",
    "HintInfo",
    "PlayerState",
    "load_save_state",
    "StateManager",
    "ArchipelagoClient",
    "DataPackageStore",
    "WsServer",
    "OperatorState",
    "create_app",
    "setup_logging",
]


async def _main() -> None:
    config = Config.from_env()
    setup_logging(config.session_id)

    log = logging.getLogger(__name__)

    initial_state = load_save_state(config.save_dir)
    goals_file = os.path.join(config.save_dir, "bridge_goals.json")
    state = StateManager(initial_state, goals_file=goals_file, save_dir=config.save_dir)

    ws_server = WsServer(config)

    recompute_event = asyncio.Event()
    ap_client = ArchipelagoClient(config, state, ws_server.broadcast, recompute_event)

    ws_server.bind(state, ap_client)

    coordinator = PauseResumeCoordinator(
        request_approve_restart=ws_server.request_approve_restart,
    )

    runtime: RuntimeAdapter
    if config.runtime == "docker":
        runtime = DockerRuntimeAdapter(config)
    elif config.runtime == "kubernetes":
        runtime = KubernetesRuntimeAdapter(config)
    else:
        runtime = SubprocessRuntimeAdapter(config)

    operator_state = OperatorState(config, runtime)
    operator_state.broadcast = ws_server.broadcast

    reachable_semaphore = asyncio.Semaphore(1)
    app = create_app(
        state, ap_client, reachable_semaphore,
        coordinator=coordinator,
        ws_server=ws_server,
        operator_state=operator_state,
    )

    uv_config = uvicorn.Config(
        app,
        host="0.0.0.0",
        port=config.rest_port,
        loop="asyncio",
        log_level="warning",
    )
    uv_server = uvicorn.Server(uv_config)
    _uv_task = asyncio.create_task(uv_server.serve())
    log.info("REST+WS API listening on port %d", config.rest_port)

    _sweep_task = asyncio.create_task(
        _reachable_sweep_loop(state, ws_server.broadcast, config.session_id, reachable_semaphore, recompute_event)
    )
    _reconcile_task = asyncio.create_task(
        _apsave_reconcile_loop(state, ws_server.broadcast, config.session_id, recompute_event)
    )
    _heartbeat_task = asyncio.create_task(
        _ws_heartbeat_loop(ws_server.broadcast, config.session_id)
    )

    try:
        await ap_client.run_with_reconnect()
    finally:
        uv_server.should_exit = True
        _uv_task.cancel()
        _sweep_task.cancel()
        _reconcile_task.cancel()
        _heartbeat_task.cancel()


if __name__ == "__main__":
    if "--check-imports" in sys.argv:
        print("script import ok")
    else:
        asyncio.run(_main())
