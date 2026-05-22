from __future__ import annotations

import os
from unittest.mock import AsyncMock, MagicMock, patch


from app.api_notifier import notify_status

API_KEY = "test-secret"


async def test_noop_when_api_url_not_set() -> None:
    with patch.dict(os.environ, {}, clear=False):
        os.environ.pop("ARCHIPELAGO_API_URL", None)
        os.environ.pop("RUNNER_SHARED_SECRET", None)

        with patch("app.api_notifier.httpx.AsyncClient") as mock_client_cls:
            await notify_status("sess-1", "generated")

        mock_client_cls.assert_not_called()


async def test_noop_when_secret_not_set() -> None:
    with patch.dict(os.environ, {"ARCHIPELAGO_API_URL": "http://api.local"}, clear=False):
        os.environ.pop("RUNNER_SHARED_SECRET", None)

        with patch("app.api_notifier.httpx.AsyncClient") as mock_client_cls:
            await notify_status("sess-1", "generated")

        mock_client_cls.assert_not_called()


async def test_posts_to_correct_url() -> None:
    env = {"ARCHIPELAGO_API_URL": "http://api.local", "RUNNER_SHARED_SECRET": "mysecret"}

    mock_response = MagicMock()
    mock_response.status_code = 200

    mock_client = AsyncMock()
    mock_client.__aenter__ = AsyncMock(return_value=mock_client)
    mock_client.__aexit__ = AsyncMock(return_value=False)
    mock_client.post = AsyncMock(return_value=mock_response)

    with patch.dict(os.environ, env), patch("app.api_notifier.httpx.AsyncClient", return_value=mock_client):
        await notify_status("sess-42", "generated")

    mock_client.post.assert_called_once()
    call_kwargs = mock_client.post.call_args
    assert call_kwargs[0][0] == "http://api.local/api/v1/sessions/sess-42/callback"
    assert call_kwargs[1]["json"]["status"] == "generated"
    assert call_kwargs[1]["headers"]["X-Runner-Secret"] == "mysecret"


async def test_posts_host_port_password_when_provided() -> None:
    env = {"ARCHIPELAGO_API_URL": "http://api.local", "RUNNER_SHARED_SECRET": "mysecret"}

    mock_response = MagicMock()
    mock_response.status_code = 200

    mock_client = AsyncMock()
    mock_client.__aenter__ = AsyncMock(return_value=mock_client)
    mock_client.__aexit__ = AsyncMock(return_value=False)
    mock_client.post = AsyncMock(return_value=mock_response)

    with patch.dict(os.environ, env), patch("app.api_notifier.httpx.AsyncClient", return_value=mock_client):
        await notify_status("sess-42", "running", host="10.0.0.1", port=9000, password="pw123")

    body = mock_client.post.call_args[1]["json"]
    assert body["status"] == "running"
    assert body["host"] == "10.0.0.1"
    assert body["port"] == 9000
    assert body["password"] == "pw123"


async def test_omits_none_fields_from_payload() -> None:
    env = {"ARCHIPELAGO_API_URL": "http://api.local", "RUNNER_SHARED_SECRET": "mysecret"}

    mock_response = MagicMock()
    mock_response.status_code = 200

    mock_client = AsyncMock()
    mock_client.__aenter__ = AsyncMock(return_value=mock_client)
    mock_client.__aexit__ = AsyncMock(return_value=False)
    mock_client.post = AsyncMock(return_value=mock_response)

    with patch.dict(os.environ, env), patch("app.api_notifier.httpx.AsyncClient", return_value=mock_client):
        await notify_status("sess-42", "generated")

    body = mock_client.post.call_args[1]["json"]
    assert "host" not in body
    assert "port" not in body
    assert "password" not in body


async def test_network_error_is_swallowed() -> None:
    env = {"ARCHIPELAGO_API_URL": "http://api.local", "RUNNER_SHARED_SECRET": "mysecret"}

    mock_client = AsyncMock()
    mock_client.__aenter__ = AsyncMock(return_value=mock_client)
    mock_client.__aexit__ = AsyncMock(return_value=False)
    mock_client.post = AsyncMock(side_effect=ConnectionError("refused"))

    with patch.dict(os.environ, env), patch("app.api_notifier.httpx.AsyncClient", return_value=mock_client):
        await notify_status("sess-42", "generated")  # must not raise


async def test_strips_trailing_slash_from_api_url() -> None:
    env = {"ARCHIPELAGO_API_URL": "http://api.local/", "RUNNER_SHARED_SECRET": "mysecret"}

    mock_response = MagicMock()
    mock_response.status_code = 200

    mock_client = AsyncMock()
    mock_client.__aenter__ = AsyncMock(return_value=mock_client)
    mock_client.__aexit__ = AsyncMock(return_value=False)
    mock_client.post = AsyncMock(return_value=mock_response)

    with patch.dict(os.environ, env), patch("app.api_notifier.httpx.AsyncClient", return_value=mock_client):
        await notify_status("sess-42", "generated")

    url = mock_client.post.call_args[0][0]
    assert "//" not in url.replace("http://", "")
