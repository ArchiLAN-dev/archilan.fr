from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any


_HINT_STATUS_NAMES = {
    0: "unspecified",
    10: "no_priority",
    20: "avoid",
    30: "priority",
    40: "found",
}


@dataclass
class HintInfo:
    receiving_player: int      # slot index of who needs the item
    finding_player: int        # slot index of whose world contains the location
    location_id: int
    item_id: int
    entrance: str              # entrance name for entrance rando, else ""
    item_flags: int            # AP item flags bitmask (progression=1, useful=2, trap=4)
    status: int                # HintStatus: 0=unspecified, 10=no_priority, 20=avoid, 30=priority, 40=found
    # Resolved names - populated by DataPackageStore after IDs are known
    receiving_player_name: str = ""
    finding_player_name: str = ""
    item_name: str = ""
    location_name: str = ""

    @property
    def found(self) -> bool:
        return self.status == 40

    @property
    def status_name(self) -> str:
        return _HINT_STATUS_NAMES.get(self.status, "unspecified")

    def to_dict(self) -> dict[str, Any]:
        return {
            "receiving_player": self.receiving_player,
            "receiving_player_name": self.receiving_player_name,
            "finding_player": self.finding_player,
            "finding_player_name": self.finding_player_name,
            "location_id": self.location_id,
            "location_name": self.location_name,
            "item_id": self.item_id,
            "item_name": self.item_name,
            "item_flags": self.item_flags,
            "entrance": self.entrance,
            "found": self.found,
            "status": self.status,
            "status_name": self.status_name,
        }


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
    hints_used: int = 0
    hint_points_per_check: int = 1
    hint_cost: int = 0  # 0 = unknown until set by apply_hint_cost_for_slot from RoomInfo WS
    _checked_locations: set[int] = field(default_factory=set, repr=False)
    _received_items: list[tuple[int, int, int]] = field(default_factory=list, repr=False)
    _hints: list[HintInfo] = field(default_factory=list, repr=False)

    @property
    def hint_points_available(self) -> int:
        return max(0, self.checks_done * self.hint_points_per_check - self.hints_used * self.hint_cost)

    def to_dict(self) -> dict[str, Any]:
        return {
            "slot_name": self.slot_name,
            "checks_done": self.checks_done,
            "checks_total": self.checks_total,
            "items_received": self.items_received,
            "client_status": self.client_status,
            "goal_reached_at": self.goal_reached_at,
            "reachable_now": self.reachable_now,
            "hints_used": self.hints_used,
            "hint_points_available": self.hint_points_available,
        }
