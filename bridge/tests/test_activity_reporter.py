from __future__ import annotations

import asyncio
from unittest.mock import AsyncMock, MagicMock

import pytest

from bridge.bridge import ArchipelagoClient, Config, MercurePublisher, StateManager


def _make_client() -> tuple[ArchipelagoClient, list[str]]:
    config = Config(
        mercure_hub_url="http://hub.test",
        central_api_secret="secret",
        symfony_internal_url="http://api.test",
        run_id="run-1",
        bridge_internal_token="bridge-token",
    )
    publisher = MagicMock(spec=MercurePublisher)
    publisher.publish = AsyncMock()
    client = ArchipelagoClient(config, StateManager(), publisher)
    reported: list[str] = []

    async def _record(activity_type: str) -> None:
        reported.append(activity_type)

    client._report_activity = _record  # type: ignore[method-assign]

    return client, reported


@pytest.mark.asyncio
@pytest.mark.parametrize(
    ("packet", "expected"),
    [
        ({"cmd": "LocationChecks", "slot": 1, "locations": [100]}, "check"),
        ({"cmd": "ReceivedItems", "items": [{"item": 1, "player": 1, "location": 100}]}, "item"),
        ({"cmd": "StatusUpdate", "slot": 1, "status": 20}, "status_update"),
        (
            {
                "cmd": "PrintJSON",
                "type": "Hint",
                "receiving": 1,
                "item": {"item": 1, "location": 100, "player": 2, "flags": 0},
                "data": [],
            },
            "hint",
        ),
        ({"cmd": "PrintJSON", "type": "Chat", "data": [{"type": "text", "text": "hello"}]}, "chat"),
    ],
)
async def test_activity_reported_for_archipelago_activity_packets(
    packet: dict[str, object],
    expected: str,
) -> None:
    client, reported = _make_client()

    await client._handle_packet(packet)
    await asyncio.sleep(0)

    assert expected in reported


@pytest.mark.asyncio
async def test_empty_received_items_does_not_report_activity() -> None:
    client, reported = _make_client()

    await client._handle_packet({"cmd": "ReceivedItems", "items": []})
    await asyncio.sleep(0)

    assert reported == []
