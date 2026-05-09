from __future__ import annotations

import threading

import pytest

from app.port_pool import PortPool


def test_allocate_returns_port_in_range() -> None:
    pool = PortPool(9000, 9002)
    port = pool.allocate()
    assert port in {9000, 9001, 9002}


def test_allocate_returns_lowest_available_port() -> None:
    pool = PortPool(9000, 9002)
    assert pool.allocate() == 9000
    assert pool.allocate() == 9001


def test_allocate_marks_port_as_in_use() -> None:
    pool = PortPool(9000, 9001)
    pool.allocate()
    assert pool.in_use == 1
    assert pool.available == 1


def test_release_returns_port_to_pool() -> None:
    pool = PortPool(9000, 9000)
    port = pool.allocate()
    assert port == 9000
    assert pool.available == 0
    pool.release(port)
    assert pool.available == 1
    assert pool.in_use == 0


def test_release_ignores_port_outside_managed_range() -> None:
    pool = PortPool(9000, 9001)
    pool.release(9999)

    assert pool.total == 2
    assert pool.available == 2
    assert pool.in_use == 0


def test_release_ignores_managed_port_that_was_not_allocated() -> None:
    pool = PortPool(9000, 9001)
    pool.release(9000)

    assert pool.total == 2
    assert pool.available == 2
    assert pool.in_use == 0


def test_rejects_invalid_range() -> None:
    with pytest.raises(ValueError):
        PortPool(9002, 9000)


def test_allocate_returns_none_when_exhausted() -> None:
    pool = PortPool(9000, 9000)
    pool.allocate()
    assert pool.allocate() is None


def test_total_stays_constant_across_allocations() -> None:
    pool = PortPool(9000, 9004)
    total = pool.total
    pool.allocate()
    pool.allocate()
    assert pool.total == total


def test_port_reusable_after_release() -> None:
    pool = PortPool(9000, 9000)
    port = pool.allocate()
    pool.release(port)
    reallocated = pool.allocate()
    assert reallocated == port


def test_concurrent_allocations_are_unique() -> None:
    pool = PortPool(9000, 9049)
    allocated: list[int | None] = []
    lock = threading.Lock()

    def worker() -> None:
        port = pool.allocate()
        with lock:
            allocated.append(port)

    threads = [threading.Thread(target=worker) for _ in range(50)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()

    non_none = [p for p in allocated if p is not None]
    assert len(non_none) == len(set(non_none)), "concurrent allocations must be unique"
    assert pool.available == 0
