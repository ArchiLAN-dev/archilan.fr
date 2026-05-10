"""Tests for TokenManager and MercurePublisher."""
from __future__ import annotations

import asyncio
from unittest.mock import AsyncMock, MagicMock

import pytest

from bridge.bridge import Config, MercurePublisher, TokenManager


def _make_config() -> Config:
    return Config(
        mercure_hub_url="http://mercure.test/.well-known/mercure",
        central_api_secret="test-secret",
        symfony_internal_url="http://api.test",
        run_id="run-abc",
    )


def _mock_response(status: int = 200, token: str = "tok") -> AsyncMock:
    resp = AsyncMock()
    resp.status = status
    resp.raise_for_status = MagicMock(side_effect=None if status < 400 else Exception("HTTP error"))
    resp.json = AsyncMock(return_value={"data": {"token": token}})
    resp.text = AsyncMock(return_value="error body")
    resp.__aenter__ = AsyncMock(return_value=resp)
    resp.__aexit__ = AsyncMock(return_value=False)
    return resp


# ---------------------------------------------------------------------------
# TokenManager
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_fetch_token_stores_token() -> None:
    config = _make_config()
    http = MagicMock()
    http.get = MagicMock(return_value=_mock_response(token="tok-123"))

    mgr = TokenManager(config, http)
    token = await mgr.fetch_token()

    assert token == "tok-123"
    assert mgr.token == "tok-123"


@pytest.mark.asyncio
async def test_fetch_token_calls_correct_url() -> None:
    config = _make_config()
    http = MagicMock()
    http.get = MagicMock(return_value=_mock_response())

    mgr = TokenManager(config, http)
    await mgr.fetch_token()

    http.get.assert_called_once_with(
        "http://api.test/api/v1/internal/sessions/run-abc/publisher-token",
        headers={"X-Internal-Secret": "test-secret"},
    )


@pytest.mark.asyncio
async def test_token_updates_after_second_fetch() -> None:
    config = _make_config()
    http = MagicMock()
    http.get = MagicMock(side_effect=[_mock_response(token="first"), _mock_response(token="second")])

    mgr = TokenManager(config, http)
    await mgr.fetch_token()
    assert mgr.token == "first"
    await mgr.fetch_token()
    assert mgr.token == "second"


@pytest.mark.asyncio
async def test_schedule_refresh_creates_task() -> None:
    config = _make_config()
    http = MagicMock()
    http.get = MagicMock(return_value=_mock_response())

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


@pytest.mark.asyncio
async def test_schedule_refresh_cancels_previous_task() -> None:
    config = _make_config()
    http = MagicMock()
    http.get = MagicMock(return_value=_mock_response())

    mgr = TokenManager(config, http)
    await mgr.fetch_token()
    mgr.schedule_refresh(interval=9999)
    first_task = mgr._refresh_task

    mgr.schedule_refresh(interval=9999)
    # A new task must have been created
    assert mgr._refresh_task is not first_task
    # The old task must be in the process of being cancelled (not still running normally).
    # cancel() is synchronous; the task finishes cancelling on the next event-loop turn.
    await asyncio.sleep(0)
    assert first_task.done()
    mgr._refresh_task.cancel()
    try:
        await mgr._refresh_task
    except asyncio.CancelledError:
        pass


# ---------------------------------------------------------------------------
# MercurePublisher
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_publish_success() -> None:
    config = _make_config()
    token_mgr = MagicMock(spec=TokenManager)
    token_mgr.token = "tok"

    resp = _mock_response(status=200)
    http = MagicMock()
    http.post = MagicMock(return_value=resp)

    pub = MercurePublisher(config, token_mgr, http)
    await pub.publish("topic/test", {"key": "value"})

    assert http.post.call_count == 1


@pytest.mark.asyncio
async def test_publish_retries_on_401() -> None:
    config = _make_config()
    token_mgr = MagicMock(spec=TokenManager)
    token_mgr.token = "tok"
    token_mgr.fetch_token = AsyncMock()

    http = MagicMock()
    http.post = MagicMock(side_effect=[_mock_response(status=401), _mock_response(status=200)])

    pub = MercurePublisher(config, token_mgr, http)
    await pub.publish("topic/test", {"key": "value"})

    token_mgr.fetch_token.assert_called_once()
    assert http.post.call_count == 2


@pytest.mark.asyncio
async def test_publish_sends_bearer_token() -> None:
    config = _make_config()
    token_mgr = MagicMock(spec=TokenManager)
    token_mgr.token = "my-bearer-token"

    resp = _mock_response(status=200)
    http = MagicMock()
    http.post = MagicMock(return_value=resp)

    pub = MercurePublisher(config, token_mgr, http)
    await pub.publish("topic/test", {"key": "value"})

    _, kwargs = http.post.call_args
    assert kwargs["headers"]["Authorization"] == "Bearer my-bearer-token"


@pytest.mark.asyncio
async def test_publish_sends_correct_topic() -> None:
    config = _make_config()
    token_mgr = MagicMock(spec=TokenManager)
    token_mgr.token = "tok"

    resp = _mock_response(status=200)
    http = MagicMock()
    http.post = MagicMock(return_value=resp)

    pub = MercurePublisher(config, token_mgr, http)
    await pub.publish("runs/run-1/players", {"slots": {}})

    _, kwargs = http.post.call_args
    assert kwargs["data"]["topic"] == "runs/run-1/players"
