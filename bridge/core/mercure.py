from __future__ import annotations

import asyncio
import json
import logging
from typing import Any

import aiohttp

from config import Config


class TokenManager:
    def __init__(self, config: Config, http_session: aiohttp.ClientSession) -> None:
        self._config = config
        self._http = http_session
        self._token: str = ""
        self._refresh_task: asyncio.Task[None] | None = None

    @property
    def token(self) -> str:
        return self._token

    async def fetch_token(self) -> str:
        url = (
            f"{self._config.symfony_internal_url}"
            f"/api/v1/internal/sessions/{self._config.run_id}/publisher-token"
        )
        async with self._http.get(
            url, headers={"X-Internal-Secret": self._config.central_api_secret}
        ) as resp:
            resp.raise_for_status()
            body = await resp.json()
            self._token = body["data"]["token"]
            logging.getLogger(__name__).info("publisher token fetched")
            return self._token

    def schedule_refresh(self, interval: int = 3000) -> None:
        if self._refresh_task and not self._refresh_task.done():
            self._refresh_task.cancel()
        self._refresh_task = asyncio.create_task(self._refresh_loop(interval))

    async def _refresh_loop(self, interval: int) -> None:
        while True:
            await asyncio.sleep(interval)
            try:
                await self.fetch_token()
            except Exception as exc:
                logging.getLogger(__name__).error("token refresh failed: %s", exc)


class MercurePublisher:
    def __init__(
        self,
        config: Config,
        token_manager: TokenManager,
        http_session: aiohttp.ClientSession,
    ) -> None:
        self._config = config
        self._tokens = token_manager
        self._http = http_session
        self._log = logging.getLogger(__name__)

    async def publish(self, topic: str, data: dict[str, Any]) -> None:
        payload = {"topic": topic, "data": json.dumps(data)}
        headers = {"Authorization": f"Bearer {self._tokens.token}"}

        async with self._http.post(self._config.mercure_hub_url, data=payload, headers=headers) as resp:
            if resp.status == 401:
                await self._tokens.fetch_token()
            elif resp.status >= 400:
                body = await resp.text()
                self._log.error("mercure publish failed %d: %s", resp.status, body)
                return
            else:
                return  # success

        # Retry once after token refresh
        headers["Authorization"] = f"Bearer {self._tokens.token}"
        async with self._http.post(self._config.mercure_hub_url, data=payload, headers=headers) as resp:
            if resp.status >= 400:
                body = await resp.text()
                self._log.error("mercure retry failed %d: %s", resp.status, body)
