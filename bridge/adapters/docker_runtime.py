from __future__ import annotations

import logging
import os
import uuid
from collections.abc import Awaitable, Callable
from typing import Any

import aiodocker

from bridge.core.config import Config
from bridge.ports.runtime import RunResult

log = logging.getLogger("bridge.adapters.docker")


class DockerRuntimeAdapter:
    """Runs AP processes as ephemeral Docker containers via the Docker socket.

    Requires DOCKER_HOST or the default Unix socket /var/run/docker.sock.
    In production, proxy the socket through tecnativa/docker-socket-proxy
    to restrict API access to the minimum required endpoints.
    """

    def __init__(self, config: Config) -> None:
        self._config = config

    def supports_generate(self) -> bool:
        return bool(self._config.ap_image)

    def supports_server(self) -> bool:
        return bool(self._config.ap_image)

    async def run_generation(
        self,
        *,
        yamls_dir: str,
        output_dir: str,
        worlds_dir: str,
        race_mode: bool = False,
        on_progress: Callable[[str], Awaitable[None]] | None = None,
    ) -> RunResult:
        cmd = [
            "python", "-m", "Generate",
            "--yaml_dir", "/data/yamls",
            "--output_dir", "/data/output",
        ]
        if race_mode:
            cmd.append("--race")

        container_config: dict[str, Any] = {
            "Image": self._config.ap_image,
            "Cmd": cmd,
            "HostConfig": {
                "Binds": [
                    f"{yamls_dir}:/data/yamls:ro",
                    f"{output_dir}:/data/output",
                    f"{worlds_dir}:/data/worlds:ro",
                ],
            },
        }
        name = f"ap-gen-{uuid.uuid4().hex[:8]}"
        lines: list[str] = []
        exit_code = 1

        async with aiodocker.Docker() as docker:
            container = await docker.containers.create(config=container_config, name=name)
            try:
                await container.start()
                raw_logs: list[str] = await container.log(stdout=True, stderr=True, follow=True)  # type: ignore[misc]
                for log_line in raw_logs:
                    line = log_line.rstrip("\n")
                    lines.append(line)
                    if on_progress:
                        await on_progress(line)
                result = await container.wait()
                exit_code = result["StatusCode"]
                log.info("docker generation exit_code=%d name=%s", exit_code, name)
            finally:
                try:
                    await container.delete(force=True)
                except Exception:
                    pass

        return RunResult(exit_code=exit_code, logs="\n".join(lines[-100:]))

    async def start_server(
        self,
        *,
        seed_path: str,
        output_dir: str,
        worlds_dir: str,
        port: int,
    ) -> str:
        seed_filename = os.path.basename(seed_path)
        container_config: dict[str, Any] = {
            "Image": self._config.ap_image,
            "Cmd": [
                "python", "MultiServer.py",
                "--host", "0.0.0.0",
                "--port", str(port),
                f"/data/output/{seed_filename}",
            ],
            "ExposedPorts": {f"{port}/tcp": {}},
            "HostConfig": {
                "Binds": [
                    f"{output_dir}:/data/output",
                    f"{worlds_dir}:/data/worlds:ro",
                ],
                "PortBindings": {f"{port}/tcp": [{"HostPort": str(port)}]},
            },
        }
        name = f"ap-server-{uuid.uuid4().hex[:8]}"

        async with aiodocker.Docker() as docker:
            container = await docker.containers.create(config=container_config, name=name)
            await container.start()
            info = await container.show()
            container_id: str = info["Id"]
            log.info("docker server started container=%s name=%s", container_id[:12], name)
            return container_id

    async def stop_server(self, handle: str) -> None:
        async with aiodocker.Docker() as docker:
            try:
                container = docker.containers.container(handle)
                await container.stop(timeout=10)
                await container.delete(force=True)
                log.info("docker server stopped container=%s", handle[:12])
            except Exception as exc:
                log.warning("docker stop error container=%s: %s", handle[:12], exc)

    async def get_yaml_template(self, game: str, *, worlds_dir: str) -> str:
        container_config: dict[str, Any] = {
            "Image": self._config.ap_image,
            "Cmd": ["python", "-m", "Generate", "--template", game],
            "HostConfig": {
                "Binds": [f"{worlds_dir}:/data/worlds:ro"],
            },
        }
        name = f"ap-tmpl-{uuid.uuid4().hex[:8]}"

        async with aiodocker.Docker() as docker:
            container = await docker.containers.create(config=container_config, name=name)
            try:
                await container.start()
                output_parts: list[str] = await container.log(stdout=True, stderr=False, follow=True)  # type: ignore[misc]
                result = await container.wait()
                if result["StatusCode"] != 0:
                    err_parts: list[str] = await container.log(stdout=False, stderr=True, follow=False)
                    raise RuntimeError("".join(err_parts)[:300])
            finally:
                try:
                    await container.delete(force=True)
                except Exception:
                    pass

        return "".join(output_parts)
