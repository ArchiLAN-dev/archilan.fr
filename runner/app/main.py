from __future__ import annotations

import asyncio
import io
import json
import logging
import os
import pathlib
import re
import shlex
import shutil
import signal
import sys
import tempfile
import threading
import uuid
import zipfile

if sys.platform == "win32":
    asyncio.set_event_loop_policy(asyncio.WindowsProactorEventLoopPolicy())
from contextlib import asynccontextmanager
from typing import Any

from dotenv import load_dotenv
from fastapi import Depends, FastAPI, File, HTTPException, Request, UploadFile
from fastapi.responses import JSONResponse, Response

from . import apworld_storage, logging_config

load_dotenv()
from .docker_manager import DockerManager
from .generator import DEFAULT_WORLD_DIR_FLAG, run_generation
from .port_pool import PortPool
from .server_lifecycle import launch_server, restart_server, stop_server
from .session_store import SessionStore
from .slot_names import generate_slot_names, validate_slot_names
from .yaml_writer import write_slot_yamls

logging_config.configure()
logger = logging.getLogger(__name__)

API_KEY: str = os.environ.get("RUNNER_API_KEY", "")
PORT_RANGE_START: int = int(os.environ.get("PORT_RANGE_START", "25000"))
PORT_RANGE_END: int = int(os.environ.get("PORT_RANGE_END", "25099"))
WORKSPACE_ROOT: str = os.environ.get("WORKSPACE_ROOT", "/workspace")
ARCHIPELAGO_GENERATE_CMD: str = os.environ.get("ARCHIPELAGO_GENERATE_CMD", "ArchipelagoGenerate")
ARCHIPELAGO_TEMPLATE_CMD: str = os.environ.get("ARCHIPELAGO_TEMPLATE_CMD", "")
ARCHIPELAGO_SERVER_IMAGE: str = os.environ.get("ARCHIPELAGO_SERVER_IMAGE", "archipelago-server:latest")
GENERATION_TIMEOUT: int = int(os.environ.get("GENERATION_TIMEOUT", "300"))
APWORLD_TEMPLATE_TIMEOUT: int = int(os.environ.get("APWORLD_TEMPLATE_TIMEOUT", "30"))
ARCHIPELAGO_WORLD_DIR_FLAG: str = os.environ.get("ARCHIPELAGO_WORLD_DIR_FLAG", DEFAULT_WORLD_DIR_FLAG)

session_store = SessionStore()

docker_manager = DockerManager()
port_pool = PortPool(PORT_RANGE_START, PORT_RANGE_END)


def _graceful_shutdown() -> None:
    """Stop all managed containers. Extracted so tests can call it directly."""
    logger.info("graceful shutdown - stopping managed containers", extra={"request_id": "-"})
    docker_manager.stop_all()


@asynccontextmanager
async def lifespan(app: FastAPI):  # type: ignore[type-arg]
    connected = docker_manager.connect()
    logger.info(
        "runner started",
        extra={"request_id": "-", "docker_connected": connected, "port_total": port_pool.total},
    )

    if threading.current_thread() is threading.main_thread():
        original_handler = signal.getsignal(signal.SIGTERM)

        def _on_sigterm(signum: int, frame: object) -> None:
            _graceful_shutdown()
            signal.signal(signal.SIGTERM, original_handler)
            sys.exit(0)

        signal.signal(signal.SIGTERM, _on_sigterm)

    yield

    docker_manager.stop_all()


app = FastAPI(title="archipelago-runner", lifespan=lifespan)


@app.middleware("http")
async def attach_request_id(request: Request, call_next: Any) -> Any:
    request_id = str(uuid.uuid4())
    request.state.request_id = request_id
    response = await call_next(request)
    response.headers["X-Request-Id"] = request_id
    return response


async def _require_api_key(request: Request) -> None:
    provided = request.headers.get("x-api-key", "")
    if not API_KEY or provided != API_KEY:
        raise HTTPException(status_code=401, detail={"error": "unauthorized"})


def _infer_game_name_from_zip(zf: zipfile.ZipFile, names: list[str]) -> str:
    """Extract game name from an APWorld that has no archipelago.json.

    Looks for ``game = "..."`` in the top-level package's ``__init__.py``.
    Falls back to the folder name if the attribute is not found.
    """
    folders = sorted({n.split("/")[0] for n in names if "/" in n})
    if not folders:
        return ""
    folder = folders[0]
    init_path = f"{folder}/__init__.py"
    if init_path in names:
        try:
            content = zf.read(init_path).decode("utf-8", errors="replace")
            match = re.search(r'\bgame\s*=\s*["\']([^"\']+)["\']', content)
            if match:
                return match.group(1)
        except Exception:
            pass
    return folder


@app.post("/apworld/upload", dependencies=[Depends(_require_api_key)])
async def upload_apworld(request: Request, file: UploadFile = File(...)) -> JSONResponse:
    filename = file.filename or ""
    if not filename.lower().endswith(".apworld"):
        return JSONResponse(
            {"error": "invalid_file", "detail": "Le fichier doit avoir l'extension .apworld."},
            status_code=422,
        )

    file_bytes = await file.read()

    try:
        storage_key = apworld_storage.store(file_bytes, filename, WORKSPACE_ROOT)
    except ValueError as exc:
        return JSONResponse({"error": "invalid_file", "detail": str(exc)}, status_code=422)

    digest = storage_key.removesuffix(".apworld")

    stored_path = apworld_storage.path(storage_key, WORKSPACE_ROOT)
    try:
        with zipfile.ZipFile(stored_path) as zf:
            names = zf.namelist()
            json_path = next(
                (n for n in names if n == "archipelago.json" or n.endswith("/archipelago.json")),
                None,
            )
            if json_path is not None:
                meta = json.loads(zf.read(json_path).decode("utf-8"))
                game_name = meta.get("game") or meta.get("name") or ""
                if not game_name:
                    return JSONResponse(
                        {"error": "invalid_apworld", "detail": "Champ 'game' absent de archipelago.json."},
                        status_code=422,
                    )
            else:
                # Older APWorld format: no archipelago.json - infer game name from __init__.py
                game_name = _infer_game_name_from_zip(zf, names)
                if not game_name:
                    return JSONResponse(
                        {"error": "invalid_apworld", "detail": "Impossible de détecter le nom du jeu (archipelago.json absent et __init__.py introuvable)."},
                        status_code=422,
                    )
    except (zipfile.BadZipFile, KeyError, json.JSONDecodeError) as exc:
        return JSONResponse({"error": "invalid_apworld", "detail": str(exc)}, status_code=422)

    workspace_tmp = pathlib.Path(WORKSPACE_ROOT) / "tmp"
    workspace_tmp.mkdir(parents=True, exist_ok=True)
    tmp_dir = tempfile.mkdtemp(dir=workspace_tmp)
    default_yaml = ""
    try:
        if not ARCHIPELAGO_TEMPLATE_CMD:
            logger.info(
                "ARCHIPELAGO_TEMPLATE_CMD not set, skipping template generation",
                extra={"request_id": getattr(request.state, "request_id", "-"), "game": game_name},
            )
        else:
            cmd = [
                *shlex.split(ARCHIPELAGO_TEMPLATE_CMD),
                "--game", game_name,
                "--outputpath", tmp_dir,
                "--world_directory", str(pathlib.Path(WORKSPACE_ROOT) / "apworlds"),
            ]
            try:
                proc = await asyncio.create_subprocess_exec(
                    *cmd,
                    stdout=asyncio.subprocess.PIPE,
                    stderr=asyncio.subprocess.PIPE,
                )
                stdout, stderr = await asyncio.wait_for(
                    proc.communicate(), timeout=APWORLD_TEMPLATE_TIMEOUT
                )
                if proc.returncode == 0:
                    default_yaml = stdout.decode(errors="replace").strip()
                else:
                    logger.warning(
                        "template generation failed (rc=%d), continuing without template",
                        proc.returncode,
                        extra={"request_id": getattr(request.state, "request_id", "-"), "game": game_name},
                    )
            except (FileNotFoundError, asyncio.TimeoutError) as exc:
                logger.warning(
                    "template generation skipped: %s",
                    exc,
                    extra={"request_id": getattr(request.state, "request_id", "-"), "game": game_name},
                )
    finally:
        shutil.rmtree(tmp_dir, ignore_errors=True)

    logger.info(
        "apworld uploaded",
        extra={
            "request_id": getattr(request.state, "request_id", "-"),
            "storageKey": storage_key,
            "game": game_name,
        },
    )
    return JSONResponse({
        "storageKey": storage_key,
        "hash": digest,
        "archipelagoGameName": game_name,
        "defaultYaml": default_yaml,
    })


@app.post("/apworld/bundled-template", dependencies=[Depends(_require_api_key)])
async def bundled_template(request: Request) -> JSONResponse:
    body: dict[str, Any] = await _json_body(request)
    game_name = (body.get("gameName") or "").strip()
    if not game_name:
        return JSONResponse({"error": "game_name_required"}, status_code=422)

    if not ARCHIPELAGO_TEMPLATE_CMD:
        return JSONResponse({"error": "template_cmd_not_configured"}, status_code=503)

    workspace_tmp = pathlib.Path(WORKSPACE_ROOT) / "tmp"
    workspace_tmp.mkdir(parents=True, exist_ok=True)
    tmp_dir = tempfile.mkdtemp(dir=workspace_tmp)
    try:
        cmd = [
            *shlex.split(ARCHIPELAGO_TEMPLATE_CMD),
            "--game", game_name,
            "--outputpath", tmp_dir,
        ]
        try:
            proc = await asyncio.create_subprocess_exec(
                *cmd,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE,
            )
            stdout, _stderr = await asyncio.wait_for(
                proc.communicate(), timeout=APWORLD_TEMPLATE_TIMEOUT
            )
        except FileNotFoundError:
            return JSONResponse({"error": "archigenerate_not_found"}, status_code=503)
        except asyncio.TimeoutError:
            return JSONResponse({"error": "template_timeout"}, status_code=503)

        if proc.returncode != 0:
            return JSONResponse({"error": "template_failed"}, status_code=422)

        default_yaml = stdout.decode(errors="replace").strip()
    finally:
        shutil.rmtree(tmp_dir, ignore_errors=True)

    logger.info(
        "bundled template generated",
        extra={"request_id": getattr(request.state, "request_id", "-"), "game": game_name},
    )
    return JSONResponse({"archipelagoGameName": game_name, "defaultYaml": default_yaml})


@app.post("/sessions/{session_id}/generate", dependencies=[Depends(_require_api_key)])
async def generate(request: Request, session_id: str) -> JSONResponse:
    existing = session_store.get(session_id)
    if existing and existing["status"] == "generating":
        return JSONResponse({"error": "already_generating"}, status_code=409)

    body: dict[str, Any] = await _json_body(request)
    seed_raw = body.get("seed")
    seed: str | None = str(seed_raw).strip() if isinstance(seed_raw, str) and seed_raw.strip() else None

    session = session_store.create(session_id)

    asyncio.create_task(
        run_generation(
            session_id,
            WORKSPACE_ROOT,
            session_store,
            generate_cmd=ARCHIPELAGO_GENERATE_CMD,
            timeout=GENERATION_TIMEOUT,
            world_dir_flag=ARCHIPELAGO_WORLD_DIR_FLAG,
            seed=seed,
        )
    )

    logger.info(
        "generation triggered",
        extra={"request_id": getattr(request.state, "request_id", "-"), "session_id": session_id},
    )
    return JSONResponse(session, status_code=202)


@app.post("/sessions/{session_id}/generate-and-launch", dependencies=[Depends(_require_api_key)])
async def generate_and_launch(request: Request, session_id: str) -> JSONResponse:
    # Guard: reject if a session is already active for this id
    existing = session_store.get(session_id)
    if existing is not None and existing.get("status") in ("generating", "generated", "running"):
        return JSONResponse({"error": "already_active", "status": existing.get("status")}, status_code=409)

    body: dict[str, Any] = await _json_body(request)
    seed_raw = body.get("seed")
    seed: str | None = str(seed_raw).strip() if isinstance(seed_raw, str) and seed_raw.strip() else None
    raw_slots: list[Any] = body.get("slots") if isinstance(body.get("slots"), list) else []
    slots = [s for s in raw_slots if isinstance(s, dict)]

    # Validate slots before touching the filesystem or session store
    if not slots:
        return JSONResponse({"error": "invalid_slots", "details": ["Au moins un slot est requis."]}, status_code=422)

    slot_errors: list[str] = []
    slot_names: list[str] = []
    for slot in slots:
        slot_name = str(slot.get("slotName", "")).strip()
        slot_names.append(slot_name)
        if not slot_name:
            slot_errors.append("slotName est requis et ne peut pas être vide.")
            continue
        if "apworldStorageKey" in slot:
            if not str(slot.get("playerYaml", "")).strip():
                slot_errors.append(f"playerYaml est requis pour le slot '{slot_name}'.")
            if not str(slot.get("apworldStorageKey", "")).strip():
                slot_errors.append(f"apworldStorageKey est requis pour le slot '{slot_name}'.")
            if not str(slot.get("apworldDownloadUrl") or "").strip():
                slot_errors.append(f"apworldDownloadUrl est requis pour le slot '{slot_name}'.")

    name_errors = validate_slot_names(slot_names)
    all_errors = slot_errors + name_errors
    if all_errors:
        return JSONResponse({"error": "invalid_slots", "details": all_errors}, status_code=422)

    try:
        write_slot_yamls(session_id, slots, WORKSPACE_ROOT)
    except Exception as exc:
        logger.error("yaml write failed: %s", exc, extra={"request_id": getattr(request.state, "request_id", "-")})
        return JSONResponse({"error": "write_failed", "details": str(exc)}, status_code=500)

    session_store.create(session_id)
    await run_generation(
        session_id,
        WORKSPACE_ROOT,
        session_store,
        generate_cmd=ARCHIPELAGO_GENERATE_CMD,
        timeout=GENERATION_TIMEOUT,
        world_dir_flag=ARCHIPELAGO_WORLD_DIR_FLAG,
        seed=seed,
    )

    session = session_store.get(session_id)
    if session is None or session.get("status") != "generated":
        error = (session or {}).get("error", "unknown")
        return JSONResponse({"error": "generation_failed", "details": error}, status_code=503)

    launch_result = await launch_server(session_id, session_store, port_pool, docker_manager, image=ARCHIPELAGO_SERVER_IMAGE)
    if "error" in launch_result:
        err = launch_result["error"]
        if err == "not_found":
            return JSONResponse(launch_result, status_code=404)
        if err in ("already_running", "not_ready"):
            return JSONResponse(launch_result, status_code=409)
        return JSONResponse(launch_result, status_code=503)

    logger.info(
        "generate-and-launch succeeded",
        extra={"request_id": getattr(request.state, "request_id", "-"), "session_id": session_id},
    )
    return JSONResponse({
        "sessionId": session_id,
        "containerHost": launch_result["containerHost"],
        "containerPort": launch_result["containerPort"],
        "serverPassword": launch_result["serverPassword"],
    })


@app.get("/sessions/{session_id}", dependencies=[Depends(_require_api_key)])
async def get_session(request: Request, session_id: str) -> JSONResponse:
    session = session_store.get(session_id)
    if session is None:
        return JSONResponse({"error": "not_found"}, status_code=404)
    return JSONResponse(session)


@app.post("/sessions/{session_id}/preflight", dependencies=[Depends(_require_api_key)])
async def preflight(request: Request, session_id: str) -> JSONResponse:
    body: dict[str, Any] = await _json_body(request)
    raw_slots: list[Any] = body.get("slots") if isinstance(body.get("slots"), list) else []

    slot_results = []
    valid = True

    valid_slots_for_naming = []
    per_slot_errors: dict[str, list[str]] = {}

    for item in raw_slots:
        if not isinstance(item, dict):
            continue
        slot_id = str(item.get("slotId", ""))
        errors: list[str] = []

        if "apworldStorageKey" in item:
            storage_key = str(item.get("apworldStorageKey") or "")
            player_yaml = str(item.get("playerYaml") or "")
            download_url = str(item.get("apworldDownloadUrl") or "")

            if not player_yaml.strip():
                errors.append("playerYaml est requis et ne peut pas être vide.")

            if storage_key and not download_url:
                errors.append(f"L'URL de telechargement de l'apworld '{storage_key}' est manquante.")

            naming_game = "Custom"
        else:
            game_name = str(item.get("archipelagoGameName") or "")
            options: list[Any] = item.get("options") if isinstance(item.get("options"), list) else []

            if not game_name.strip():
                errors.append("Ce jeu n'a pas de nom Archipelago configuré.")

            for opt in options:
                if not isinstance(opt, dict):
                    continue
                if opt.get("required") is True:
                    has_value = _has_option_value(opt.get("currentValue"))
                    has_default = _has_option_value(opt.get("defaultValue"))
                    if not has_value and not has_default:
                        errors.append(f"L'option '{opt.get('key', '?')}' est requise mais n'a pas de valeur.")

            naming_game = game_name or "Unknown"

        per_slot_errors[slot_id] = errors
        if errors:
            valid = False

        valid_slots_for_naming.append({
            "slotId": slot_id,
            "playerName": str(item.get("playerName") or "Player"),
            "archipelagoGameName": naming_game,
        })

    proposed_names = generate_slot_names(valid_slots_for_naming)

    for slot_id, errors in per_slot_errors.items():
        slot_results.append({
            "slotId": slot_id,
            "proposedName": proposed_names.get(slot_id, ""),
            "errors": errors,
        })

    logger.info(
        "preflight",
        extra={"request_id": getattr(request.state, "request_id", "-"), "session_id": session_id, "valid": valid},
    )
    return JSONResponse({"valid": valid, "slots": slot_results})


@app.post("/sessions/{session_id}/yamls", dependencies=[Depends(_require_api_key)])
async def generate_yamls(request: Request, session_id: str) -> JSONResponse:
    body: dict[str, Any] = await _json_body(request)
    raw_slots: list[Any] = body.get("slots") if isinstance(body.get("slots"), list) else []

    slots = [s for s in raw_slots if isinstance(s, dict)]
    slot_names = [str(s.get("slotName", "")) for s in slots]

    name_errors = validate_slot_names(slot_names)
    if name_errors:
        return JSONResponse({"error": "invalid_slot_names", "details": name_errors}, status_code=422)

    prepared: list[dict[str, Any]] = []
    for s in slots:
        if "apworldStorageKey" in s:
            slot_data: dict[str, str] = {
                "slotName": str(s.get("slotName", "")),
                "apworldStorageKey": str(s.get("apworldStorageKey", "")),
                "playerYaml": str(s.get("playerYaml", "")),
            }
            download_url = str(s.get("apworldDownloadUrl") or "")
            if download_url:
                slot_data["apworldDownloadUrl"] = download_url
            prepared.append(slot_data)
        else:
            prepared.append({
                "slotName": str(s.get("slotName", "")),
                "archipelagoGameName": str(s.get("archipelagoGameName", "")),
                "options": s.get("options") if isinstance(s.get("options"), dict) else {},
            })

    try:
        files = write_slot_yamls(session_id, prepared, WORKSPACE_ROOT)
    except Exception as exc:
        logger.error("yaml write failed: %s", exc, extra={"request_id": getattr(request.state, "request_id", "-")})
        return JSONResponse({"error": "write_failed", "details": [str(exc)]}, status_code=500)

    logger.info(
        "yamls written",
        extra={"request_id": getattr(request.state, "request_id", "-"), "session_id": session_id, "count": len(files)},
    )
    return JSONResponse({"files": files})


@app.post("/sessions/{session_id}/launch", dependencies=[Depends(_require_api_key)])
async def launch(request: Request, session_id: str) -> JSONResponse:
    result = await launch_server(session_id, session_store, port_pool, docker_manager, image=ARCHIPELAGO_SERVER_IMAGE)
    if "error" in result:
        err = result["error"]
        if err == "not_found":
            return JSONResponse(result, status_code=404)
        if err in ("already_running", "not_ready"):
            return JSONResponse(result, status_code=409)
        return JSONResponse(result, status_code=503)
    logger.info(
        "server launched",
        extra={"request_id": getattr(request.state, "request_id", "-"), "session_id": session_id},
    )
    return JSONResponse(result)


@app.post("/sessions/{session_id}/restart", dependencies=[Depends(_require_api_key)])
async def restart(request: Request, session_id: str) -> JSONResponse:
    result = await restart_server(session_id, session_store, port_pool, docker_manager, image=ARCHIPELAGO_SERVER_IMAGE)
    if "error" in result:
        err = result["error"]
        if err == "not_found":
            return JSONResponse(result, status_code=404)
        if err in ("not_ready", "not_crashed"):
            return JSONResponse(result, status_code=409)
        return JSONResponse(result, status_code=503)
    logger.info(
        "server restarted",
        extra={"request_id": getattr(request.state, "request_id", "-"), "session_id": session_id},
    )
    return JSONResponse(result)


@app.get("/sessions/{session_id}/yamls.zip", dependencies=[Depends(_require_api_key)])
async def download_yamls_zip(request: Request, session_id: str) -> Response:
    yamls_dir = pathlib.Path(WORKSPACE_ROOT) / session_id / "yamls"
    if not yamls_dir.exists():
        return JSONResponse({"error": "not_found"}, status_code=404)

    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as zf:
        for yaml_file in sorted(yamls_dir.glob("*.yaml")):
            zf.write(yaml_file, yaml_file.name)
    buf.seek(0)

    logger.info(
        "yamls zip served",
        extra={"request_id": getattr(request.state, "request_id", "-"), "session_id": session_id},
    )
    return Response(
        content=buf.read(),
        media_type="application/zip",
        headers={"Content-Disposition": f'attachment; filename="session-{session_id}.zip"'},
    )


@app.get("/sessions/{session_id}/output", dependencies=[Depends(_require_api_key)])
async def list_output_files(request: Request, session_id: str) -> JSONResponse:
    """List patch files (non-.archipelago) in a session's output directory."""
    output_dir = pathlib.Path(WORKSPACE_ROOT) / session_id / "output"
    if not output_dir.exists():
        return JSONResponse({"files": []})
    files = sorted(
        f.name for f in output_dir.iterdir()
        if f.is_file() and f.suffix.lower() != ".archipelago"
    )
    logger.info(
        "output files listed",
        extra={"request_id": getattr(request.state, "request_id", "-"), "session_id": session_id, "count": len(files)},
    )
    return JSONResponse({"files": files})


@app.get("/sessions/{session_id}/output/{filename}", dependencies=[Depends(_require_api_key)])
async def get_output_file(request: Request, session_id: str, filename: str) -> Response:
    """Serve a single patch file from a session's output directory."""
    output_dir = (pathlib.Path(WORKSPACE_ROOT) / session_id / "output").resolve()
    try:
        file_path = (output_dir / filename).resolve()
    except Exception:
        raise HTTPException(status_code=400, detail="Invalid filename")

    if not file_path.is_relative_to(output_dir):
        raise HTTPException(status_code=400, detail="Invalid filename")

    if not file_path.is_file() or file_path.suffix.lower() == ".archipelago":
        return JSONResponse({"error": "not_found"}, status_code=404)

    content = file_path.read_bytes()
    logger.info(
        "output file served",
        extra={"request_id": getattr(request.state, "request_id", "-"), "session_id": session_id, "filename": filename},
    )
    return Response(
        content=content,
        media_type="application/octet-stream",
        headers={"Content-Disposition": f'attachment; filename="{filename}"'},
    )


@app.delete("/sessions/{session_id}", dependencies=[Depends(_require_api_key)])
async def delete_session(request: Request, session_id: str) -> JSONResponse:
    result = await stop_server(session_id, session_store, port_pool, docker_manager)
    if "error" in result:
        err = result["error"]
        if err == "not_found":
            return JSONResponse(result, status_code=404)
        return JSONResponse(result, status_code=500)
    logger.info(
        "server stopped",
        extra={"request_id": getattr(request.state, "request_id", "-"), "session_id": session_id},
    )
    return JSONResponse(result)


async def _json_body(request: Request) -> dict[str, Any]:
    try:
        payload = await request.json()
        return payload if isinstance(payload, dict) else {}
    except Exception:
        return {}


def _has_option_value(value: Any) -> bool:
    return value is not None and value != ""


@app.get("/health", dependencies=[Depends(_require_api_key)])
async def health(request: Request) -> JSONResponse:
    connected = docker_manager.is_connected()
    logger.info("health check", extra={"request_id": getattr(request.state, "request_id", "-")})
    return JSONResponse(
        {
            "status": "ok" if connected else "degraded",
            "docker": {"connected": connected},
            "ports": {
                "total": port_pool.total,
                "available": port_pool.available,
                "in_use": port_pool.in_use,
            },
        }
    )
