from __future__ import annotations

import logging
import threading
from typing import TYPE_CHECKING

import docker
import docker.errors

if TYPE_CHECKING:
    import docker.models.containers

logger = logging.getLogger(__name__)


class DockerManager:
    """Wraps the Docker SDK client and tracks containers this service owns."""

    def __init__(self) -> None:
        self._client: docker.DockerClient | None = None
        self._containers: dict[str, docker.models.containers.Container] = {}
        self._lock = threading.Lock()

    def connect(self) -> bool:
        """Attempt to connect to the Docker daemon. Returns True on success."""
        try:
            client = docker.from_env()
            client.ping()
            self._client = client
            return True
        except Exception as exc:
            logger.warning("docker connection failed: %s", exc)
            return False

    def is_connected(self) -> bool:
        if self._client is None:
            return False
        try:
            self._client.ping()
            return True
        except Exception:
            return False

    def track(self, container_id: str, container: docker.models.containers.Container) -> None:
        """Register a container as managed by this service."""
        with self._lock:
            self._containers[container_id] = container

    def untrack(self, container_id: str) -> None:
        """Remove a container from the managed set."""
        with self._lock:
            self._containers.pop(container_id, None)

    @property
    def client(self) -> docker.DockerClient | None:
        return self._client

    def run_container(
        self,
        image: str,
        host_port: int,
        output_dir: str,
        password: str,
        container_port: int = 38281,
    ) -> docker.models.containers.Container:
        """Start an Archipelago server container and track it. Raises if not connected."""
        if self._client is None:
            raise RuntimeError("Docker not connected")
        container = self._client.containers.run(
            image,
            detach=True,
            ports={f"{container_port}/tcp": host_port},
            volumes={output_dir: {"bind": "/archipelago/output", "mode": "ro"}},
            environment={"PASSWORD": password},
            remove=True,
        )
        self.track(str(container.id), container)
        return container

    def stop_container(self, container_id: str) -> None:
        """Stop and untrack a managed container."""
        with self._lock:
            container = self._containers.get(container_id)
        if container is None:
            return
        try:
            container.stop(timeout=10)
        except Exception as exc:
            logger.warning("failed to stop container %s: %s", container_id, exc)
        finally:
            self.untrack(container_id)

    def stop_all(self) -> None:
        """Stop every managed container. Called on graceful shutdown."""
        with self._lock:
            items = list(self._containers.items())
            self._containers.clear()

        for container_id, container in items:
            try:
                logger.info("stopping container %s", container_id)
                container.stop(timeout=10)
            except Exception as exc:
                logger.warning("failed to stop container %s: %s", container_id, exc)
