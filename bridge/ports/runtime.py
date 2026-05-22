from __future__ import annotations

from collections.abc import Awaitable, Callable
from dataclasses import dataclass
from typing import Protocol, runtime_checkable


@dataclass
class RunResult:
    exit_code: int
    logs: str


@runtime_checkable
class RuntimeAdapter(Protocol):
    """Port: executes AP lifecycle operations in a given execution environment."""

    def supports_generate(self) -> bool: ...
    def supports_server(self) -> bool: ...

    async def run_generation(
        self,
        *,
        yamls_dir: str,
        output_dir: str,
        worlds_dir: str,
        race_mode: bool = False,
        on_progress: Callable[[str], Awaitable[None]] | None = None,
    ) -> RunResult: ...

    async def start_server(
        self,
        *,
        seed_path: str,
        output_dir: str,
        worlds_dir: str,
        port: int,
    ) -> str: ...

    async def stop_server(self, handle: str) -> None: ...

    async def get_yaml_template(self, game: str, *, worlds_dir: str) -> str: ...
