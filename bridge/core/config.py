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
    ap_admin_password: str = ""

    save_dir: str = "/archipelago/output"
    rest_port: int = 5000

    slot_names: list[dict[str, str]] = field(default_factory=list)

    # Runtime adapter: "subprocess" | "docker" | "kubernetes"
    runtime: str = "subprocess"
    ap_image: str = ""             # container image (docker / kubernetes runtimes)
    ap_k8s_namespace: str = "default"

    # AP process management (subprocess runtime)
    ap_pid_file: str = "/tmp/ap.pid"
    ap_launch_cmd: str = ""   # resume: appends --savefile=<path>
    ap_start_cmd: str = ""    # first launch: appends <seed_file>

    # Operator: directories and generation
    ap_worlds_dir: str = "/archipelago/worlds"
    ap_yamls_dir: str = "/archipelago/yamls"
    ap_output_dir: str = "/archipelago/output"
    ap_generate_cmd: str = ""
    ap_template_cmd: str = ""  # cmd to generate a YAML template: appends <game_name>

    # Object storage (S3-compatible)
    storage_endpoint: str = ""
    storage_access_key: str = ""
    storage_secret_key: str = ""
    storage_bucket: str = "archipelago-saves"

    @classmethod
    def from_env(cls) -> Config:
        slot_names: list[dict[str, str]] = json.loads(os.environ.get("SLOT_NAMES", "[]"))
        return cls(
            session_id=os.environ["SESSION_ID"],
            internal_token=os.environ["INTERNAL_TOKEN"],
            ap_ws_url=os.environ.get("AP_WS_URL", "ws://localhost:38281"),
            ap_server_password=os.environ.get("AP_SERVER_PASSWORD", ""),
            ap_admin_password=os.environ.get("AP_ADMIN_PASSWORD", ""),
            slot_names=slot_names,
            rest_port=int(os.environ.get("REST_PORT", "5000")),
            save_dir=os.environ.get("SAVE_DIR", "/archipelago/output"),
            runtime=os.environ.get("AP_RUNTIME", "subprocess"),
            ap_image=os.environ.get("AP_IMAGE", ""),
            ap_k8s_namespace=os.environ.get("AP_K8S_NAMESPACE", "default"),
            ap_pid_file=os.environ.get("AP_PID_FILE", "/tmp/ap.pid"),
            ap_launch_cmd=os.environ.get("AP_LAUNCH_CMD", ""),
            ap_start_cmd=os.environ.get("AP_START_CMD", ""),
            ap_worlds_dir=os.environ.get("AP_WORLDS_DIR", "/archipelago/worlds"),
            ap_yamls_dir=os.environ.get("AP_YAMLS_DIR", "/archipelago/yamls"),
            ap_output_dir=os.environ.get("AP_OUTPUT_DIR", "/archipelago/output"),
            ap_generate_cmd=os.environ.get("AP_GENERATE_CMD", ""),
            ap_template_cmd=os.environ.get("AP_TEMPLATE_CMD", ""),
            storage_endpoint=os.environ.get("STORAGE_ENDPOINT", ""),
            storage_access_key=os.environ.get("STORAGE_ACCESS_KEY", ""),
            storage_secret_key=os.environ.get("STORAGE_SECRET_KEY", ""),
            storage_bucket=os.environ.get("STORAGE_BUCKET", "archipelago-saves"),
        )
