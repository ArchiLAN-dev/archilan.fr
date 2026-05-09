"""Tests for TokenManager - fetch on startup, re-fetch on 401 (AC #9, #13)."""
from __future__ import annotations

import asyncio
from unittest.mock import AsyncMock, MagicMock, patch

import pytest
import pytest_asyncio

from bridge.bridge import Config, TokenManager


def _make_config() -> Config:
    return Config(
        mercure_hub_url="http://mercure.test/.well-known/mercure",
        central_api_secret="test-secret",
        symfony_internal_url="http://api.test",
        run_id="run-abc",
    )


def _mock_http_response(token: str, status: int = 200) -> AsyncMock:
    resp = AsyncMock()
    resp.status = status
    resp.raise_for_status = MagicMock(side_effect=None if status < 400 else Exception("HTTP error"))
    resp.json = AsyncMock(return_value={"data": {"token": token}})
    resp.__aenter__ = AsyncMock(return_value=resp)
    resp.__aexit__ = AsyncMock(return_value=False)
    return resp


@pytest.mark.asyncio
async def test_fetch_token_stores_token() -> None:
    config = _make_config()
    http = MagicMock()
    http.get = MagicMock(return_value=_mock_http_response("tok-123"))

    mgr = TokenManager(config, http)
    token = await mgr.fetch_token()

    assert token == "tok-123"
    assert mgr.token == "tok-123"


@pytest.mark.asyncio
async def test_fetch_token_calls_correct_url() -> None:
    config = _make_config()
    http = MagicMock()
    http.get = MagicMock(return_value=_mock_http_response("tok-456"))

    mgr = TokenManager(config, http)
    await mgr.fetch_token()

    http.get.assert_called_once_with(
        "http://api.test/api/v1/internal/sessions/run-abc/publisher-token",
        headers={"X-Internal-Secret": "test-secret"},
    )


@pytest.mark.asyncio
async def test_fetch_token_uses_internal_secret_header() -> None:
    config = _make_config()
    http = MagicMock()
    http.get = MagicMock(return_value=_mock_http_response("tok-789"))

    mgr = TokenManager(config, http)
    await mgr.fetch_token()

    _, kwargs = http.get.call_args
    assert kwargs.get("headers", {}).get("X-Internal-Secret") == "test-secret"


@pytest.mark.asyncio
async def test_token_updates_after_second_fetch() -> None:
    config = _make_config()
    http = MagicMock()
    calls = [_mock_http_response("first"), _mock_http_response("second")]
    http.get = MagicMock(side_effect=calls)

    mgr = TokenManager(config, http)
    await mgr.fetch_token()
    assert mgr.token == "first"
    await mgr.fetch_token()
    assert mgr.token == "second"


@pytest.mark.asyncio
async def test_schedule_refresh_creates_task() -> None:
    config = _make_config()
    http = MagicMock()
    http.get = MagicMock(return_value=_mock_http_response("tok"))

    mgr = TokenManager(config, http)
    await mgr.fetch_token()
    mgr.schedule_refresh(interval=9999)

    assert mgr._refresh_task is not None
    assert not mgr._refresh_task.done()
    mgr._refresh_task.cancel()
    try:
        await mgr._refresh_task
    except asyncio.CancelledError:
        pass
