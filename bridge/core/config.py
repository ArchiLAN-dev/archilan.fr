from __future__ import annotations

import json
import os
from dataclasses import dataclass, field


@dataclass
class Config:
    mercure_hub_url: str
    central_api_secret: str
    symfony_internal_url: str
    run_id: str
    archipelago_ws_url: str = "ws://localhost:38281"
    save_dir: str = "/archipelago/output"
    rest_port: int = 5000
    token_refresh_interval: int = 3000  # 50 minutes

    slot_names: list[dict[str, str]] = field(default_factory=list)
    server_password: str = ""
    admin_password: str = ""
    bridge_internal_token: str = ""

    # AP process management (for pause / wake-on-connect)
    ap_pid_file: str = "/tmp/ap.pid"
    ap_launch_cmd: str = ""  # full shell command to (re-)launch the AP process

    # MinIO (for save upload on pause)
    minio_endpoint: str = ""
    minio_access_key: str = ""
    minio_secret_key: str = ""
    minio_bucket: str = "archipelago-saves"

    @classmethod
    def from_env(cls) -> Config:
        slot_names: list[dict[str, str]] = json.loads(os.environ.get("SLOT_NAMES", "[]"))
        return cls(
            mercure_hub_url=os.environ["MERCURE_HUB_URL"],
            central_api_secret=os.environ["CENTRAL_API_SECRET"],
            symfony_internal_url=os.environ["SYMFONY_INTERNAL_URL"],
            run_id=os.environ["RUN_ID"],
            slot_names=slot_names,
            server_password=os.environ.get("PASSWORD", ""),
            admin_password=os.environ.get("SERVER_PASSWORD", ""),
            bridge_internal_token=os.environ.get("BRIDGE_INTERNAL_TOKEN", ""),
            ap_pid_file=os.environ.get("AP_PID_FILE", "/tmp/ap.pid"),
            ap_launch_cmd=os.environ.get("AP_LAUNCH_CMD", ""),
            minio_endpoint=os.environ.get("MINIO_ENDPOINT", ""),
            minio_access_key=os.environ.get("MINIO_ACCESS_KEY", ""),
            minio_secret_key=os.environ.get("MINIO_SECRET_KEY", ""),
            minio_bucket=os.environ.get("MINIO_BUCKET", "archipelago-saves"),
        )
