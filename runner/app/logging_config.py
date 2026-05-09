from __future__ import annotations

import datetime
import json
import logging


class _JsonFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        extra = {
            k: v
            for k, v in record.__dict__.items()
            if k not in logging.LogRecord.__dict__ and not k.startswith("_")
        }
        payload = {
            "timestamp": datetime.datetime.now(datetime.timezone.utc).isoformat(),
            "severity": record.levelname,
            "message": record.getMessage(),
            "request_id": extra.pop("request_id", "-"),
            "logger": record.name,
        }
        payload.update(extra)
        return json.dumps(payload, ensure_ascii=False)


def configure() -> None:
    handler = logging.StreamHandler()
    handler.setFormatter(_JsonFormatter())
    root = logging.getLogger()
    root.handlers = [handler]
    root.setLevel(logging.INFO)
    for name in ("uvicorn.access",):
        logging.getLogger(name).propagate = False
