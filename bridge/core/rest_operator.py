from __future__ import annotations

import asyncio
import glob as _glob
import io as _io
import json
import logging
import os as _os
import re as _re
import uuid
import zipfile
from dataclasses import dataclass
from datetime import datetime, timezone as _timezone
from typing import TYPE_CHECKING, Any

from fastapi import APIRouter, Depends, File, Form, HTTPException, UploadFile

from .config import Config
from .deps import BroadcastFn, get_operator, require_auth
from .schemas import (
    ApworldInfo,
    ApworldsResponse,
    GenerateResponse,
    GenerationJobResponse,
    OkResponse,
    ServerInfoResponse,
    ServerStartRequest,
    ServerStartResponse,
    YamlTemplateResponse,
)

from bridge.adapters.subprocess_runtime import SubprocessRuntimeAdapter

if TYPE_CHECKING:
    from bridge.ports.runtime import RuntimeAdapter

log = logging.getLogger("bridge.operator")

router = APIRouter(
    prefix="/operator",
    tags=["Operator"],
    dependencies=[Depends(require_auth)],
)


# ---------------------------------------------------------------------------
# State models
# ---------------------------------------------------------------------------

@dataclass
class GenerationJob:
    job_id: str
    race_mode: bool = False
    status: str = "pending"
    started_at: datetime | None = None
    finished_at: datetime | None = None
    seed: int | None = None
    seed_file: str | None = None
    spoiler_file: str | None = None
    error: str | None = None
    message: str | None = None
    progress: str = ""

    def to_dict(self) -> dict[str, Any]:
        d: dict[str, Any] = {
            "jobId": self.job_id,
            "status": self.status,
            "startedAt": self.started_at.isoformat() if self.started_at else None,
        }
        if self.status in ("done", "failed"):
            d["finishedAt"] = self.finished_at.isoformat() if self.finished_at else None
        if self.status == "running":
            d["progress"] = self.progress
        if self.status == "done":
            d["seed"] = self.seed
            d["seedFile"] = self.seed_file
            d["spoilerFile"] = self.spoiler_file
            d["raceMode"] = self.race_mode
        if self.status == "failed":
            d["error"] = self.error
            d["message"] = self.message
        return d


@dataclass
class ServerInfo:
    running: bool = False
    handle: str | None = None  # PID str (subprocess), container ID (Docker), pod name (k8s)
    port: int | None = None
    seed_file: str | None = None
    started_at: datetime | None = None

    @property
    def pid(self) -> int | None:
        """Subprocess compat: parse PID from handle when using SubprocessRuntimeAdapter."""
        try:
            return int(self.handle) if self.handle else None
        except (ValueError, TypeError):
            return None

    def to_dict(self) -> dict[str, Any]:
        if self.running:
            return {
                "running": True,
                "handle": self.handle,
                "pid": self.pid,
                "port": self.port,
                "seedFile": self.seed_file,
                "startedAt": self.started_at.isoformat() if self.started_at else None,
            }
        return {
            "running": False,
            "handle": None,
            "pid": None,
            "port": None,
            "seedFile": None,
            "startedAt": None,
        }


class OperatorState:
    def __init__(
        self,
        config: Config,
        runtime: RuntimeAdapter | None = None,
    ) -> None:
        self._config = config
        if runtime is None:
            runtime = SubprocessRuntimeAdapter(config)
        self._runtime: RuntimeAdapter = runtime
        self._jobs: dict[str, GenerationJob] = {}
        self._current_job_id: str | None = None
        self.server = ServerInfo()
        self.broadcast: BroadcastFn | None = None

    @property
    def current_job(self) -> GenerationJob | None:
        if self._current_job_id is None:
            return None
        return self._jobs.get(self._current_job_id)

    def is_job_running(self) -> bool:
        job = self.current_job
        return job is not None and job.status in ("pending", "running")

    def new_job(self, race_mode: bool) -> GenerationJob:
        job_id = f"gen-{uuid.uuid4().hex[:8]}"
        job = GenerationJob(job_id=job_id, race_mode=race_mode)
        self._jobs[job_id] = job
        self._current_job_id = job_id
        return job

    def get_job(self, job_id: str) -> GenerationJob | None:
        return self._jobs.get(job_id)


# ---------------------------------------------------------------------------
# Apworld helpers
# ---------------------------------------------------------------------------

def _parse_apworld_meta(zip_path: str) -> tuple[str | None, str | None]:
    """Return (game_name, version) from an apworld zip. Both may be None."""
    try:
        with zipfile.ZipFile(zip_path) as zf:
            names = zf.namelist()

            if "manifest.json" in names:
                try:
                    data = json.loads(zf.read("manifest.json"))
                    return data.get("game"), data.get("version")
                except Exception:
                    pass

            init_files = [n for n in names if _re.match(r"^worlds/[^/]+/__init__\.py$", n)]
            if not init_files:
                return None, None

            content = zf.read(init_files[0]).decode("utf-8", errors="replace")
            game_m = _re.search(r'\bgame\s*=\s*["\']([^"\']+)["\']', content)
            ver_m = _re.search(r'__version__\s*=\s*["\']([^"\']+)["\']', content)
            return (
                game_m.group(1) if game_m else None,
                ver_m.group(1) if ver_m else None,
            )
    except Exception:
        return None, None


def _list_apworlds(worlds_dir: str) -> list[dict[str, Any]]:
    if not _os.path.isdir(worlds_dir):
        return []
    result = []
    for path in sorted(_glob.glob(_os.path.join(worlds_dir, "*.apworld"))):
        filename = _os.path.basename(path)
        game, version = _parse_apworld_meta(path)
        result.append({"filename": filename, "game": game, "version": version})
    return result


# ---------------------------------------------------------------------------
# Generation helpers
# ---------------------------------------------------------------------------

def _find_latest_archipelago(output_dir: str, not_before: datetime) -> str | None:
    """Find the newest .archipelago file created at or after `not_before`."""
    candidates: list[tuple[datetime, str]] = []
    for path in _glob.glob(_os.path.join(output_dir, "*.archipelago")):
        try:
            mtime = datetime.fromtimestamp(_os.path.getmtime(path), tz=_timezone.utc)
            if mtime >= not_before:
                candidates.append((mtime, path))
        except OSError:
            pass
    return max(candidates)[1] if candidates else None


def _extract_seed_from_filename(filename: str) -> int | None:
    m = _re.search(r"AP_(\d+)", _os.path.basename(filename))
    if m:
        try:
            return int(m.group(1))
        except ValueError:
            pass
    return None


async def _run_generation(job: GenerationJob, operator: OperatorState) -> None:
    config = operator._config
    broadcast = operator.broadcast

    job.status = "running"
    job.started_at = datetime.now(_timezone.utc)
    started_at = job.started_at

    async def _on_progress(line: str) -> None:
        job.progress = line
        if broadcast:
            await broadcast("generation_progress", {
                "type": "generation_progress",
                "jobId": job.job_id,
                "progress": line,
            })

    log.info("generation: start job=%s", job.job_id)
    try:
        result = await operator._runtime.run_generation(
            yamls_dir=config.ap_yamls_dir,
            output_dir=config.ap_output_dir,
            worlds_dir=config.ap_worlds_dir,
            race_mode=job.race_mode,
            on_progress=_on_progress,
        )

        if result.exit_code != 0:
            _finish_failed(job, "generation_failed", f"process exited with code {result.exit_code}")
            if broadcast:
                await broadcast("generation_failed", _failed_payload(job))
            return

        seed_path = _find_latest_archipelago(config.ap_output_dir, started_at)
        if seed_path is None:
            _finish_failed(job, "generation_failed", "no .archipelago file found after generation")
            if broadcast:
                await broadcast("generation_failed", _failed_payload(job))
            return

        seed_filename = _os.path.basename(seed_path)
        job.seed_file = seed_filename
        job.seed = _extract_seed_from_filename(seed_filename)

        spoiler_path = seed_path.replace(".archipelago", "_Spoiler.txt")
        job.spoiler_file = _os.path.basename(spoiler_path) if _os.path.exists(spoiler_path) else None

        job.status = "done"
        job.finished_at = datetime.now(_timezone.utc)
        log.info("generation: done job=%s seed=%s", job.job_id, job.seed_file)
        if broadcast:
            await broadcast("generation_done", {
                "type": "generation_done",
                "jobId": job.job_id,
                "seed": job.seed,
                "seedFile": job.seed_file,
                "spoilerFile": job.spoiler_file,
                "raceMode": job.race_mode,
            })

    except Exception as exc:
        _finish_failed(job, "generation_failed", str(exc))
        log.exception("generation: unexpected error job=%s", job.job_id)
        if broadcast:
            await broadcast("generation_failed", _failed_payload(job))


def _finish_failed(job: GenerationJob, error: str, message: str) -> None:
    job.status = "failed"
    job.error = error
    job.message = message
    job.finished_at = datetime.now(_timezone.utc)
    log.error("generation: failed job=%s error=%s msg=%s", job.job_id, error, message)


def _failed_payload(job: GenerationJob) -> dict[str, Any]:
    return {"type": "generation_failed", "jobId": job.job_id, "error": job.error, "message": job.message}


# ---------------------------------------------------------------------------
# Server helper
# ---------------------------------------------------------------------------

async def _tcp_probe(host: str, port: int, timeout: float = 30.0) -> bool:
    deadline = asyncio.get_event_loop().time() + timeout
    while asyncio.get_event_loop().time() < deadline:
        try:
            _reader, writer = await asyncio.wait_for(
                asyncio.open_connection(host, port),
                timeout=2.0,
            )
            writer.close()
            try:
                await writer.wait_closed()
            except Exception:
                pass
            return True
        except (OSError, asyncio.TimeoutError):
            await asyncio.sleep(2)
    return False


# ---------------------------------------------------------------------------
# Route handlers — apworlds
# ---------------------------------------------------------------------------

@router.get("/apworlds", response_model=ApworldsResponse)
async def get_apworlds(op: OperatorState = Depends(get_operator)) -> ApworldsResponse:
    items = _list_apworlds(op._config.ap_worlds_dir)
    return ApworldsResponse(apworlds=[ApworldInfo(**i) for i in items])


@router.post("/apworlds", response_model=ApworldInfo, status_code=201)
async def post_apworld(
    file: UploadFile = File(...),
    op: OperatorState = Depends(get_operator),
) -> ApworldInfo:
    data = await file.read()
    upload_filename = _os.path.basename(file.filename or "")

    if not upload_filename:
        raise HTTPException(
            status_code=400,
            detail={"error": "invalid_apworld", "message": "multipart field 'file' is required"},
        )

    filename = upload_filename if upload_filename.endswith(".apworld") else f"{upload_filename}.apworld"

    if not zipfile.is_zipfile(_io.BytesIO(data)):
        raise HTTPException(
            status_code=400,
            detail={"error": "invalid_apworld", "message": "file is not a valid zip archive"},
        )

    dest = _os.path.join(op._config.ap_worlds_dir, filename)
    if _os.path.exists(dest):
        raise HTTPException(
            status_code=409,
            detail={"error": "already_exists", "message": f"{filename} is already installed; use PUT to overwrite"},
        )

    _os.makedirs(op._config.ap_worlds_dir, exist_ok=True)
    with open(dest, "wb") as fh:
        fh.write(data)

    game, version = _parse_apworld_meta(dest)
    log.info("apworld installed: %s game=%s version=%s", filename, game, version)
    return ApworldInfo(filename=filename, game=game, version=version)


@router.put("/apworlds/{filename}", response_model=ApworldInfo)
async def put_apworld(
    filename: str,
    file: UploadFile = File(...),
    op: OperatorState = Depends(get_operator),
) -> ApworldInfo:
    data = await file.read()

    if not zipfile.is_zipfile(_io.BytesIO(data)):
        raise HTTPException(
            status_code=400,
            detail={"error": "invalid_apworld", "message": "file is not a valid zip archive"},
        )

    _os.makedirs(op._config.ap_worlds_dir, exist_ok=True)
    dest = _os.path.join(op._config.ap_worlds_dir, filename)
    with open(dest, "wb") as fh:
        fh.write(data)

    game, version = _parse_apworld_meta(dest)
    log.info("apworld updated: %s game=%s version=%s", filename, game, version)
    return ApworldInfo(filename=filename, game=game, version=version)


@router.delete("/apworlds/{filename}", status_code=204)
async def delete_apworld(
    filename: str,
    op: OperatorState = Depends(get_operator),
) -> None:
    dest = _os.path.join(op._config.ap_worlds_dir, filename)
    if not _os.path.exists(dest):
        raise HTTPException(status_code=404, detail="not_found")
    _os.remove(dest)
    log.info("apworld removed: %s", filename)


@router.get("/apworlds/{filename}/yaml-template", response_model=YamlTemplateResponse)
async def get_apworld_yaml_template(
    filename: str,
    op: OperatorState = Depends(get_operator),
) -> YamlTemplateResponse:
    apworld_path = _os.path.join(op._config.ap_worlds_dir, filename)
    if not _os.path.exists(apworld_path):
        raise HTTPException(status_code=404, detail="not_found")

    game, _ = _parse_apworld_meta(apworld_path)
    if not game:
        raise HTTPException(
            status_code=503,
            detail={"error": "generation_failed", "message": "cannot determine game name from apworld"},
        )

    try:
        yaml_content = await op._runtime.get_yaml_template(
            game, worlds_dir=op._config.ap_worlds_dir
        )
        return YamlTemplateResponse(game=game, filename=filename, yaml=yaml_content)
    except asyncio.TimeoutError:
        raise HTTPException(
            status_code=503,
            detail={"error": "generation_failed", "message": "template command timed out"},
        ) from None
    except Exception as exc:
        log.exception("yaml template error for %s", filename)
        raise HTTPException(
            status_code=503,
            detail={"error": "generation_failed", "message": str(exc)},
        ) from exc


# ---------------------------------------------------------------------------
# Route handlers — generation
# ---------------------------------------------------------------------------

@router.post("/generate", response_model=GenerateResponse, status_code=202)
async def post_generate(
    yamls: list[UploadFile] = File(default=[]),
    race: str | None = Form(default=None),
    op: OperatorState = Depends(get_operator),
) -> GenerateResponse:
    if not op._runtime.supports_generate():
        raise HTTPException(status_code=503, detail="runtime_not_configured")

    if op.is_job_running():
        raise HTTPException(status_code=409, detail="job_already_running")

    yaml_files: list[tuple[str, bytes]] = []
    for upload in yamls:
        fname = upload.filename or "player.yaml"
        content = await upload.read()
        yaml_files.append((fname, content))

    if not yaml_files:
        raise HTTPException(status_code=422, detail="no_yamls")

    race_mode = isinstance(race, str) and race.strip().lower() == "true"

    yamls_dir = op._config.ap_yamls_dir
    _os.makedirs(yamls_dir, exist_ok=True)
    for old in _glob.glob(_os.path.join(yamls_dir, "*.yaml")):
        try:
            _os.remove(old)
        except OSError:
            pass
    for fname, data in yaml_files:
        with open(_os.path.join(yamls_dir, _os.path.basename(fname)), "wb") as fh:
            fh.write(data)

    _os.makedirs(op._config.ap_output_dir, exist_ok=True)

    job = op.new_job(race_mode)
    asyncio.create_task(_run_generation(job, op))
    log.info("generation: job created job_id=%s race=%s yamls=%d", job.job_id, race_mode, len(yaml_files))
    return GenerateResponse(jobId=job.job_id)


@router.get("/jobs/{jobId}", response_model=GenerationJobResponse)
async def get_job(
    jobId: str,
    op: OperatorState = Depends(get_operator),
) -> GenerationJobResponse:
    job = op.get_job(jobId)
    if job is None:
        raise HTTPException(status_code=404, detail="not_found")
    return GenerationJobResponse.model_validate(job.to_dict())


# ---------------------------------------------------------------------------
# Route handlers — server management
# ---------------------------------------------------------------------------

@router.get("/server", response_model=ServerInfoResponse)
async def get_server(op: OperatorState = Depends(get_operator)) -> ServerInfoResponse:
    return ServerInfoResponse.model_validate(op.server.to_dict())


@router.post("/server/start", response_model=ServerStartResponse)
async def post_server_start(
    body: ServerStartRequest,
    op: OperatorState = Depends(get_operator),
) -> ServerStartResponse:
    if op.server.running:
        raise HTTPException(status_code=409, detail="already_running")

    if not op._runtime.supports_server():
        raise HTTPException(status_code=503, detail="runtime_not_configured")

    seed_path = _os.path.join(op._config.ap_output_dir, body.seedFile)
    if not _os.path.exists(seed_path):
        raise HTTPException(status_code=404, detail="seed_not_found")

    try:
        ap_port = int(op._config.ap_ws_url.rsplit(":", 1)[-1])
    except (ValueError, IndexError):
        ap_port = 38281

    log.info("server: starting seed=%s", body.seedFile)
    try:
        handle = await op._runtime.start_server(
            seed_path=seed_path,
            output_dir=op._config.ap_output_dir,
            worlds_dir=op._config.ap_worlds_dir,
            port=ap_port,
        )
    except Exception as exc:
        log.error("server: launch error: %s", exc)
        raise HTTPException(
            status_code=503,
            detail={"error": "launch_failed", "message": str(exc)},
        ) from exc

    healthy = await _tcp_probe("localhost", ap_port, timeout=30.0)
    if not healthy:
        try:
            await op._runtime.stop_server(handle)
        except Exception:
            pass
        raise HTTPException(status_code=504, detail="server_health_timeout")

    op.server.running = True
    op.server.handle = handle
    op.server.port = ap_port
    op.server.seed_file = body.seedFile
    op.server.started_at = datetime.now(_timezone.utc)

    if op.broadcast:
        await op.broadcast("server_started", {
            "type": "server_started",
            "handle": op.server.handle,
            "pid": op.server.pid,
            "port": op.server.port,
            "seedFile": op.server.seed_file,
        })

    log.info("server: started handle=%s port=%d", handle, ap_port)
    return ServerStartResponse(ok=True, handle=handle, pid=op.server.pid, port=ap_port)


@router.post("/server/stop", response_model=OkResponse)
async def post_server_stop(op: OperatorState = Depends(get_operator)) -> OkResponse:
    if not op.server.running:
        raise HTTPException(status_code=409, detail="not_running")

    seed_file = op.server.seed_file
    handle = op.server.handle

    if handle:
        try:
            await op._runtime.stop_server(handle)
            log.info("server: stopped handle=%s", handle)
        except Exception as exc:
            log.error("server: stop error: %s", exc)

    op.server.running = False
    op.server.handle = None

    if op.broadcast:
        await op.broadcast("server_stopped", {
            "type": "server_stopped",
            "seedFile": seed_file,
        })

    return OkResponse()
