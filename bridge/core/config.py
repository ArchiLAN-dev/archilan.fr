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
        )
