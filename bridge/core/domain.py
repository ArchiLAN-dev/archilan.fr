from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any


@dataclass
class PlayerState:
    slot_index: int
    slot_name: str = ""
    checks_done: int = 0
    checks_total: int = 0
    items_received: int = 0
    client_status: int = 0
    goal_reached_at: str | None = None
    reachable_now: int | None = None
    _checked_locations: set[int] = field(default_factory=set, repr=False)
    # Full item history: (item_id, sender_slot, location_id) — populated from apsave at
    # startup, then maintained in real-time from WS PrintJSON/ItemSend events.
    _received_items: list[tuple[int, int, int]] = field(default_factory=list, repr=False)

    def to_dict(self) -> dict[str, Any]:
        return {
            "slot_name": self.slot_name,
            "checks_done": self.checks_done,
            "checks_total": self.checks_total,
            "items_received": self.items_received,
            "client_status": self.client_status,
            "goal_reached_at": self.goal_reached_at,
            "reachable_now": self.reachable_now,
        }
