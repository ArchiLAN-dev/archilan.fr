from __future__ import annotations

import json
import os
from dataclasses import dataclass, field


@dataclass
class Config:
    session_id: str
    internal_token: str

    ap_ws_url: str = "ws://localhost:38281"
    ap_server_password: str = ""

    save_dir: str = "/archipelago/output"
    rest_port: int = 5000

    slot_names: list[dict[str, str]] = field(default_factory=list)

    # Runtime adapter: "docker" for ephemeral containers, anything else = no Docker
    runtime: str = "none"
    ap_image: str = ""    # AP Docker image (required when runtime="docker")
    ap_network: str = ""  # Docker network the bridge container is attached to
    ap_worlds_dir: str = "/data/worlds"  # path to custom apworld files inside the session volume

    ap_admin_password: str = ""  # AP server admin password (enables !admin commands)

    # Symfony central API — for bridge→API heartbeat
    central_api_url: str = ""
    central_api_secret: str = ""

    # AP process management for pause/resume flow (subprocess runtime)
    ap_pid_file: str = "/tmp/ap.pid"
    ap_launch_cmd: str = ""  # resume: appends --savefile=<path>

    @classmethod
    def from_env(cls) -> Config:
        slot_names: list[dict[str, str]] = json.loads(os.environ.get("SLOT_NAMES", "[]"))
        return cls(
            session_id=os.environ["SESSION_ID"],
            internal_token=os.environ["INTERNAL_TOKEN"],
            ap_ws_url=os.environ.get("AP_WS_URL", "ws://localhost:38281"),
            ap_server_password=os.environ.get("AP_SERVER_PASSWORD", ""),
            slot_names=slot_names,
            rest_port=int(os.environ.get("REST_PORT", "5000")),
            save_dir=os.environ.get("SAVE_DIR", "/archipelago/output"),
            runtime=os.environ.get("AP_RUNTIME", "none"),
            ap_image=os.environ.get("AP_IMAGE", ""),
            ap_network=os.environ.get("AP_NETWORK", ""),
            ap_worlds_dir=os.environ.get("AP_WORLDS_DIR", "/data/worlds"),
            ap_admin_password=os.environ.get("AP_ADMIN_PASSWORD", ""),
            central_api_url=os.environ.get("CENTRAL_API_URL", ""),
            central_api_secret=os.environ.get("CENTRAL_API_SECRET", ""),
            ap_pid_file=os.environ.get("AP_PID_FILE", "/tmp/ap.pid"),
            ap_launch_cmd=os.environ.get("AP_LAUNCH_CMD", ""),
        )
