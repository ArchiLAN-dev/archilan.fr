from __future__ import annotations

import logging
import os
from typing import Any

import httpx

logger = logging.getLogger(__name__)


async def notify_status(
    session_id: str,
    status: str,
    *,
    host: str | None = None,
    port: int | None = None,
    password: str | None = None,
    logs: str | None = None,
) -> None:
    """POST a status change to the Symfony API callback endpoint.

    No-op when ARCHIPELAGO_API_URL or RUNNER_SHARED_SECRET is not configured.
    Network errors are logged as warnings and swallowed - the runner must not
    fail because a callback could not be delivered.
    """
    api_url = os.environ.get("ARCHIPELAGO_API_URL", "")
    api_secret = os.environ.get("RUNNER_SHARED_SECRET", "")

    if not api_url or not api_secret:
        return

    payload: dict[str, Any] = {"status": status}
    if host is not None:
        payload["host"] = host
    if port is not None:
        payload["port"] = port
    if password is not None:
        payload["password"] = password
    if logs is not None:
        payload["logs"] = logs

    url = f"{api_url.rstrip('/')}/api/v1/sessions/{session_id}/callback"

    try:
        async with httpx.AsyncClient() as client:
            response = await client.post(
                url,
                json=payload,
                headers={"X-Runner-Secret": api_secret},
                timeout=5.0,
            )
            if response.status_code >= 400:
                logger.warning(
                    "api callback returned %d for session %s status=%s",
                    response.status_code,
                    session_id,
                    status,
                )
    except Exception as exc:
        logger.warning("api callback failed for session %s: %s", session_id, exc)
