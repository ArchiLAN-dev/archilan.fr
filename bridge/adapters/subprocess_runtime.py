from __future__ import annotations

import asyncio
import os
import shlex
import signal
from collections.abc import Awaitable, Callable

from bridge.core.config import Config
from bridge.ports.runtime import RunResult


class SubprocessRuntimeAdapter:
    """Runs AP processes as local subprocesses (bare-metal / VPS without Docker)."""

    def __init__(self, config: Config) -> None:
        self._config = config

    def supports_generate(self) -> bool:
        return bool(self._config.ap_generate_cmd)

    def supports_server(self) -> bool:
        return bool(self._config.ap_start_cmd)

    async def run_generation(
        self,
        *,
        yamls_dir: str,
        output_dir: str,
        worlds_dir: str,
        race_mode: bool = False,
        on_progress: Callable[[str], Awaitable[None]] | None = None,
    ) -> RunResult:
        cmd = self._config.ap_generate_cmd
        if race_mode:
            cmd = f"{cmd} --race"

        process = await asyncio.create_subprocess_shell(
            cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.STDOUT,
        )
        assert process.stdout is not None

        lines: list[str] = []
        async for raw in process.stdout:
            line = raw.decode("utf-8", errors="replace").rstrip()
            lines.append(line)
            if on_progress:
                await on_progress(line)

        await process.wait()
        return RunResult(
            exit_code=process.returncode or 0,
            logs="\n".join(lines[-100:]),
        )

    async def start_server(
        self,
        *,
        seed_path: str,
        output_dir: str,
        worlds_dir: str,
        port: int,
    ) -> str:
        cmd = f"{self._config.ap_start_cmd} {shlex.quote(seed_path)}"
        process = await asyncio.create_subprocess_shell(
            cmd,
            stdout=asyncio.subprocess.DEVNULL,
            stderr=asyncio.subprocess.DEVNULL,
        )
        pid_str = str(process.pid)
        try:
            with open(self._config.ap_pid_file, "w") as fh:
                fh.write(pid_str)
        except OSError:
            pass
        return pid_str

    async def stop_server(self, handle: str) -> None:
        try:
            os.kill(int(handle), signal.SIGTERM)
        except (ValueError, ProcessLookupError, OSError):
            pass
        try:
            os.remove(self._config.ap_pid_file)
        except OSError:
            pass

    async def get_yaml_template(self, game: str, *, worlds_dir: str) -> str:
        if not self._config.ap_template_cmd:
            raise RuntimeError("AP_TEMPLATE_CMD is not configured")
        cmd = f"{self._config.ap_template_cmd} {shlex.quote(game)}"
        process = await asyncio.create_subprocess_shell(
            cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
        stdout, stderr = await asyncio.wait_for(process.communicate(), timeout=15.0)
        if process.returncode != 0:
            raise RuntimeError(stderr.decode(errors="replace")[:300])
        return stdout.decode("utf-8", errors="replace")
