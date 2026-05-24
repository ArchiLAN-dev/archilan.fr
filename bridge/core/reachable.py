from __future__ import annotations

import asyncio
import glob
import json
import logging
import os
import sys
from typing import Any

from .state import StateManager

_reachable_cache: dict[int, tuple[tuple, dict]] = {}

_reachable_daemons: dict[int, asyncio.subprocess.Process] = {}
_daemon_ready_events: dict[int, asyncio.Event] = {}


async def _start_daemon(slot: int, arch_file: str, log: logging.Logger) -> None:
    """Start reachable.py in --daemon mode for a slot and wait for it to signal ready."""
    event = asyncio.Event()
    _daemon_ready_events[slot] = event
    output_dir = os.environ.get("AP_OUTPUT_DIR", os.environ.get("ARCHIPELAGO_OUTPUT_DIR", "/data/output"))
    yamls_dir = os.environ.get("AP_YAMLS_DIR", os.path.join(os.path.dirname(output_dir), "yamls"))
    cmd = [
        sys.executable, "/reachable/reachable.py",
        "--archipelago", arch_file,
        "--yamls", yamls_dir,
        "--slot", str(slot),
        "--daemon",
    ]
    try:
        proc = await asyncio.create_subprocess_exec(
            *cmd,
            stdin=asyncio.subprocess.PIPE,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.DEVNULL,
        )
        _reachable_daemons[slot] = proc
        log.info("reachable daemon: started slot=%d pid=%d", slot, proc.pid)
        assert proc.stdout is not None
        line = await asyncio.wait_for(proc.stdout.readline(), timeout=180.0)
        data = json.loads(line.decode())
        if data.get("ready"):
            event.set()
            log.info("reachable daemon: ready slot=%d", slot)
        else:
            log.warning("reachable daemon: unexpected first line slot=%d: %s", slot, line)
    except asyncio.TimeoutError:
        log.error("reachable daemon: startup timeout slot=%d", slot)
    except Exception as exc:
        log.error("reachable daemon: startup failed slot=%d: %s", slot, exc)


async def _compute_reachable(
    slot: int,
    state: StateManager,
    semaphore: asyncio.Semaphore,
    log: logging.Logger,
    runtime: Any = None,
) -> tuple[dict | None, str]:
    """Run reachability for a slot. Returns (result_dict, error_msg).

    Cache keyed on (checks_done, items_received).
    """
    ps = state._states.get(slot)

    output_dir = os.environ.get("AP_OUTPUT_DIR", os.environ.get("ARCHIPELAGO_OUTPUT_DIR", "/data/output"))
    arch_files = sorted(
        glob.glob(f"{output_dir}/*.archipelago") or glob.glob(f"{output_dir}/*.zip"),
        key=os.path.getmtime,
        reverse=True,
    )
    if not arch_files:
        return None, "no .archipelago file"

    yamls_dir = os.environ.get("AP_YAMLS_DIR", os.path.join(os.path.dirname(output_dir), "yamls"))

    checks_done = len(ps._checked_locations) if ps else 0
    items_received = len(ps._received_items) if ps else 0
    cache_key = (checks_done, items_received)

    cached = _reachable_cache.get(slot)
    if cached and cached[0] == cache_key:
        return {**cached[1], "cached": True}, ""

    log.info("reachable: running slot=%d cache_key=%s", slot, cache_key)

    state_payload = json.dumps({
        "checked_locations": list(ps._checked_locations) if ps else [],
        "received_items": list(ps._received_items) if ps else [],
    })

    async with semaphore:
        # Docker mode: delegate to ephemeral AP container via runtime adapter.
        if runtime is not None and hasattr(runtime, "run_reachable"):
            try:
                output = await asyncio.wait_for(
                    runtime.run_reachable(
                        slot=slot,
                        arch_file=arch_files[0],
                        yamls_dir=yamls_dir,
                        state_json=state_payload,
                    ),
                    timeout=120.0,
                )
            except asyncio.TimeoutError:
                return None, "reachability check timed out"
            except Exception as exc:
                return None, str(exc)
            try:
                result = json.loads(output)
            except json.JSONDecodeError:
                return None, "invalid JSON from reachable.py"
            _reachable_cache[slot] = (cache_key, result)
            log.info("reachable: docker slot=%d reachable=%d",
                     slot, result.get("counts", {}).get("reachable_now", 0))
            return result, ""

        # Subprocess / daemon path (non-Docker mode).
        state_payload_nl = state_payload + "\n"
        daemon_proc = _reachable_daemons.get(slot)
        daemon_event = _daemon_ready_events.get(slot)
        if (daemon_proc and daemon_proc.returncode is None
                and daemon_event and daemon_event.is_set()):
            try:
                assert daemon_proc.stdin is not None and daemon_proc.stdout is not None
                daemon_proc.stdin.write(state_payload_nl.encode())
                await daemon_proc.stdin.drain()
                resp = await asyncio.wait_for(daemon_proc.stdout.readline(), timeout=10.0)
                result = json.loads(resp.decode())
                _reachable_cache[slot] = (cache_key, result)
                log.info("reachable: daemon slot=%d reachable=%d",
                         slot, result.get("counts", {}).get("reachable_now", 0))
                return result, ""
            except Exception as exc:
                log.warning("reachable: daemon failed slot=%d %s - subprocess fallback", slot, exc)
                _daemon_ready_events.pop(slot, None)

        existing = _reachable_daemons.get(slot)
        if existing is None or existing.returncode is not None:
            asyncio.create_task(_start_daemon(slot, arch_files[0], log))

        cmd = [
            sys.executable, "/reachable/reachable.py",
            "--archipelago", arch_files[0],
            "--yamls", yamls_dir,
            "--slot", str(slot),
        ]
        try:
            proc = await asyncio.create_subprocess_exec(
                *cmd,
                stdin=asyncio.subprocess.PIPE,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE,
            )
            stdout, stderr = await asyncio.wait_for(
                proc.communicate(input=state_payload_nl.encode()), timeout=120.0
            )
        except asyncio.TimeoutError:
            return None, "reachability check timed out"
        except Exception as exc:
            return None, str(exc)

    if proc.returncode != 0:
        err_msg = ""
        if stdout.strip():
            try:
                err_msg = json.loads(stdout).get("error", "")
            except json.JSONDecodeError:
                pass
        if not err_msg:
            err_msg = (
                stderr.decode("utf-8", errors="replace").strip().splitlines()[-1]
                if stderr.strip() else "reachable.py failed"
            )
        log.warning("reachable: reachable.py failed slot=%d: %s", slot, err_msg)
        return None, err_msg

    try:
        result = json.loads(stdout)
    except json.JSONDecodeError:
        return None, "invalid JSON from reachable.py"

    _reachable_cache[slot] = (cache_key, result)
    log.info("reachable: done slot=%d reachable=%d",
             slot, result.get("counts", {}).get("reachable_now", 0))
    return result, ""
