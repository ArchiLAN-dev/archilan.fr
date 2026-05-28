from __future__ import annotations

import logging
from typing import Any

import aiodocker

from bridge.core.config import Config

log = logging.getLogger("bridge.adapters.docker")


class DockerRuntimeAdapter:
    """Runs AP scripts as ephemeral Docker containers (reachable + save-parse only).

    Generation and server lifecycle are handled by the Symfony orchestrator.
    """

    def __init__(self, config: Config) -> None:
        self._config = config

    def _volume_bind(self) -> str:
        return f"archilan_session_{self._config.session_id}:/data"

    def _network_config(self) -> dict[str, Any]:
        if not self._config.ap_network:
            return {}
        return {"EndpointsConfig": {self._config.ap_network: {}}}

    async def run_reachable(
        self,
        *,
        slot: int,
        arch_file: str,
        yamls_dir: str,
        state_json: str,
    ) -> str:
        """Run reachable.py in an ephemeral AP container and return stdout (JSON)."""
        cmd = [
            "/reachable/reachable.py",
            "--archipelago", arch_file,
            "--yamls", yamls_dir,
            "--slot", str(slot),
        ]
        container_config: dict[str, Any] = {
            "Image": self._config.ap_image,
            "Entrypoint": ["python"],
            "Cmd": cmd,
            "Env": [
                f"REACHABLE_STATE_JSON={state_json}",
                f"AP_WORLDS_DIR={self._config.ap_worlds_dir}",
            ],
            "HostConfig": {
                "Binds": [self._volume_bind()],
            },
        }

        async with aiodocker.Docker() as docker:
            container = await docker.containers.create(config=container_config)
            try:
                await container.start()
                result = await container.wait()
                output_parts: list[str] = await container.log(stdout=True, stderr=False, follow=False)
                if result["StatusCode"] != 0:
                    err_parts: list[str] = await container.log(stdout=False, stderr=True, follow=False)
                    raise RuntimeError("".join(err_parts)[:300])
            finally:
                try:
                    await container.delete(force=True)
                except Exception:
                    pass

        return "".join(output_parts)

    async def run_save_parse(self, *, save_dir: str) -> str:
        """Run read_save.py in an ephemeral AP container and return stdout (JSON)."""
        cmd = ["/readsave/read_save.py", "--save-dir", save_dir]
        container_config: dict[str, Any] = {
            "Image": self._config.ap_image,
            "Entrypoint": ["python"],
            "Cmd": cmd,
            "HostConfig": {
                "Binds": [self._volume_bind()],
            },
        }

        async with aiodocker.Docker() as docker:
            container = await docker.containers.create(config=container_config)
            try:
                await container.start()
                result = await container.wait()
                output_parts: list[str] = await container.log(stdout=True, stderr=False, follow=False)
                if result["StatusCode"] != 0:
                    err_parts: list[str] = await container.log(stdout=False, stderr=True, follow=False)
                    raise RuntimeError("".join(err_parts)[:300])
            finally:
                try:
                    await container.delete(force=True)
                except Exception:
                    pass

        return "".join(output_parts)
