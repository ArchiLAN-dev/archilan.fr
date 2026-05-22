from __future__ import annotations

from unittest.mock import MagicMock

import pytest

from app.docker_manager import DockerManager


# ─── DockerManager.stop_all unit tests ───────────────────────────────────────

def test_stop_all_stops_every_tracked_container() -> None:
    manager = DockerManager()
    c1 = MagicMock()
    c2 = MagicMock()
    manager.track("aaa", c1)
    manager.track("bbb", c2)

    manager.stop_all()

    c1.stop.assert_called_once_with(timeout=10)
    c2.stop.assert_called_once_with(timeout=10)


def test_stop_all_clears_container_registry() -> None:
    manager = DockerManager()
    manager.track("aaa", MagicMock())
    manager.stop_all()
    # Second call should be a no-op (no containers left)
    manager.stop_all()  # must not raise


def test_stop_all_continues_despite_container_error() -> None:
    manager = DockerManager()
    bad = MagicMock()
    bad.stop.side_effect = RuntimeError("container gone")
    good = MagicMock()
    manager.track("bad", bad)
    manager.track("good", good)

    manager.stop_all()  # must not raise

    good.stop.assert_called_once_with(timeout=10)


# ─── Graceful shutdown function tests ────────────────────────────────────────

def test_graceful_shutdown_calls_stop_all(monkeypatch: pytest.MonkeyPatch) -> None:
    import app.main as main_module

    mock_manager = MagicMock(spec=DockerManager)
    monkeypatch.setattr(main_module, "docker_manager", mock_manager)

    main_module._graceful_shutdown()

    mock_manager.stop_all.assert_called_once()


def test_graceful_shutdown_is_idempotent(monkeypatch: pytest.MonkeyPatch) -> None:
    import app.main as main_module

    mock_manager = MagicMock(spec=DockerManager)
    monkeypatch.setattr(main_module, "docker_manager", mock_manager)

    main_module._graceful_shutdown()
    main_module._graceful_shutdown()

    assert mock_manager.stop_all.call_count == 2
