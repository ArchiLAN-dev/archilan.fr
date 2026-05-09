from __future__ import annotations

import asyncio
import logging
import secrets
import socket
from typing import TYPE_CHECKING, Any

from .api_notifier import notify_status

if TYPE_CHECKING:
    from .docker_manager import DockerManager
    from .port_pool import PortPool
    from .session_store import SessionStore

logger = logging.getLogger(__name__)

_HEALTH_CHECK_FAILURES_BEFORE_CRASH = 3


def _tcp_ping(host: str, port: int, timeout: float = 2.0) -> bool:
    try:
        with socket.create_connection((host, port), timeout=timeout):
            return True
    except OSError:
        return False


async def _health_check_loop(
    session_id: str,
    store: SessionStore,
    port_pool: PortPool,
    docker_manager: DockerManager,
    *,
    interval: int = 30,
) -> None:
    failures = 0
    while True:
        await asyncio.sleep(interval)
        session = store.get(session_id)
        if session is None or session["status"] != "running":
            break
        port = session.get("containerPort")
        if port is None:
            break
        ok = _tcp_ping("127.0.0.1", port)
        if ok:
            failures = 0
        else:
            failures += 1
            logger.warning("health check failure %d for session %s", failures, session_id)
            if failures >= _HEALTH_CHECK_FAILURES_BEFORE_CRASH:
                container_id = session.get("containerId")
                if container_id:
                    docker_manager.stop_container(container_id)
                port_pool.release(port)
                store.update(session_id, status="crashed", containerPort=None, containerId=None)
                logger.error("session %s crashed after %d consecutive health check failures", session_id, failures)
                await notify_status(session_id, "crashed")
                break


async def launch_server(
    session_id: str,
    store: SessionStore,
    port_pool: PortPool,
    docker_manager: DockerManager,
    *,
    image: str,
) -> dict[str, Any]:
    session = store.get(session_id)
    if session is None:
        return {"error": "not_found"}

    if session["status"] == "running":
        return {"error": "already_running"}

    if session["status"] != "generated" or session.get("outputFile") is None:
        return {"error": "not_ready"}

    port = port_pool.allocate()
    if port is None:
        return {"error": "no_ports_available"}

    output_file: str = session["outputFile"]
    password = secrets.token_urlsafe(12)

    try:
        container = docker_manager.run_container(
            image=image,
            host_port=port,
            output_file=output_file,
            password=password,
        )
        container_id = str(container.id)
    except Exception as exc:
        port_pool.release(port)
        logger.error("failed to start container for session %s: %s", session_id, exc)
        return {"error": "container_start_failed", "details": str(exc)}

    store.update(
        session_id,
        status="running",
        containerPort=port,
        containerHost="0.0.0.0",
        serverPassword=password,
        containerId=container_id,
    )

    asyncio.create_task(_health_check_loop(session_id, store, port_pool, docker_manager))
    await notify_status(session_id, "running", host="0.0.0.0", port=port, password=password)

    return {
        "containerHost": "0.0.0.0",
        "containerPort": port,
        "serverPassword": password,
    }


async def stop_server(
    session_id: str,
    store: SessionStore,
    port_pool: PortPool,
    docker_manager: DockerManager,
) -> dict[str, Any]:
    session = store.get(session_id)
    if session is None:
        return {"error": "not_found"}

    container_id = session.get("containerId")
    port = session.get("containerPort")

    if container_id:
        docker_manager.stop_container(container_id)
    if port is not None:
        port_pool.release(port)

    store.update(session_id, status="stopped", containerPort=None, containerId=None, serverPassword=None)
    return {"status": "stopped"}


async def restart_server(
    session_id: str,
    store: SessionStore,
    port_pool: PortPool,
    docker_manager: DockerManager,
    *,
    image: str,
) -> dict[str, Any]:
    session = store.get(session_id)
    if session is None:
        return {"error": "not_found"}

    if session["status"] != "crashed":
        return {"error": "not_crashed"}

    if session.get("outputFile") is None:
        return {"error": "not_ready"}

    container_id = session.get("containerId")
    current_port = session.get("containerPort")

    if container_id:
        docker_manager.stop_container(container_id)
    if current_port is not None:
        port_pool.release(current_port)

    store.update(session_id, status="generated", containerPort=None, containerId=None, serverPassword=None)

    return await launch_server(session_id, store, port_pool, docker_manager, image=image)
