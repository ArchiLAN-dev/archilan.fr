from __future__ import annotations

from pydantic import BaseModel, field_validator

from .domain import HintInfo


# ---------------------------------------------------------------------------
# Request models
# ---------------------------------------------------------------------------

class CommandRequest(BaseModel):
    command: str

    @field_validator("command")
    @classmethod
    def not_empty(cls, v: str) -> str:
        if not v.strip():
            raise ValueError("command must not be empty")
        return v


class DeathLinkRequest(BaseModel):
    source: str = ""
    cause: str | None = None


class HintRequest(BaseModel):
    locationId: int
    free: bool = False


class HintStatusUpdateRequest(BaseModel):
    status: int

    @field_validator("status")
    @classmethod
    def valid_status(cls, v: int) -> int:
        if v not in {0, 10, 20, 30, 40}:
            raise ValueError("status must be one of: 0, 10, 20, 30, 40")
        return v


class ServerStartRequest(BaseModel):
    seedFile: str


# ---------------------------------------------------------------------------
# Response models
# ---------------------------------------------------------------------------

class OkResponse(BaseModel):
    ok: bool = True


class HealthResponse(BaseModel):
    status: str
    wsConnected: bool
    sessionId: str


class HintItemResponse(BaseModel):
    receivingPlayer: int
    receivingPlayerName: str
    findingPlayer: int
    findingPlayerName: str
    locationId: int
    locationName: str
    itemId: int
    itemName: str
    itemFlags: int
    entrance: str
    found: bool
    status: int
    statusName: str

    @classmethod
    def from_hint(cls, hint: HintInfo) -> "HintItemResponse":
        return cls(
            receivingPlayer=hint.receiving_player,
            receivingPlayerName=hint.receiving_player_name,
            findingPlayer=hint.finding_player,
            findingPlayerName=hint.finding_player_name,
            locationId=hint.location_id,
            locationName=hint.location_name,
            itemId=hint.item_id,
            itemName=hint.item_name,
            itemFlags=hint.item_flags,
            entrance=hint.entrance,
            found=hint.found,
            status=hint.status,
            statusName=hint.status_name,
        )


class HintsResponse(BaseModel):
    slot: int
    hints: list[HintItemResponse]
    hintsUsed: int
    hintPointsAvailable: int
    hintCost: int


class HintOkResponse(BaseModel):
    ok: bool
    slot: int
    locationId: int
    free: bool



class ItemLocationResponse(BaseModel):
    itemId: int
    itemName: str
    locationId: int
    locationName: str
    findingPlayer: int
    findingPlayerName: str | None
    checkStatus: str


class ItemLocationsResponse(BaseModel):
    slot: int
    locations: list[ItemLocationResponse]


# ---------------------------------------------------------------------------
# Checks endpoint
# ---------------------------------------------------------------------------

class CheckItemContent(BaseModel):
    id: int
    name: str
    flags: int
    receivingSlot: int
    receivingPlayerName: str


class CheckLocation(BaseModel):
    locationId: int
    locationName: str
    checked: bool
    item: CheckItemContent | None = None


class ChecksResponse(BaseModel):
    slot: int
    total: int
    checkedCount: int
    locations: list[CheckLocation]


# ---------------------------------------------------------------------------
# Slot items endpoint
# ---------------------------------------------------------------------------

class SlotItemFoundAt(BaseModel):
    findingSlot: int
    findingPlayerName: str
    locationId: int
    locationName: str
    checked: bool


class SlotItem(BaseModel):
    id: int
    name: str
    flags: int
    received: bool
    foundAt: SlotItemFoundAt | None = None


class SlotItemsResponse(BaseModel):
    slot: int
    totalOwned: int
    receivedCount: int
    items: list[SlotItem]


# ---------------------------------------------------------------------------
# Slot detail endpoint
# ---------------------------------------------------------------------------

class SlotDetailResponse(BaseModel):
    slot: int
    name: str
    game: str
    type: str
    status: str
    connected: bool
    checksDone: int
    checksTotal: int
    itemsReceived: int
    goalReachedAt: str | None = None
    reachableNow: int | None = None
    budget: int


# ---------------------------------------------------------------------------
# Spoiler / missing-items endpoints
# ---------------------------------------------------------------------------

class LocationPlacementResponse(BaseModel):
    locationId: int
    locationName: str
    itemId: int
    itemName: str
    receivingSlot: int
    receivingPlayerName: str


class MissingItemsResponse(BaseModel):
    slot: int
    missing: list[LocationPlacementResponse]


class SlotSpoilerResponse(BaseModel):
    placements: list[LocationPlacementResponse]


# ---------------------------------------------------------------------------
# Spheres endpoint
# ---------------------------------------------------------------------------

class SphereResponse(BaseModel):
    index: int
    locations: list[LocationPlacementResponse]


class SpheresResponse(BaseModel):
    cached: bool
    spheres: list[SphereResponse]
