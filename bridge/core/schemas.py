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


class ResumeRequest(BaseModel):
    saveKey: str | None = None


class HintRequest(BaseModel):
    locationId: int
    free: bool = False


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


class ApworldInfo(BaseModel):
    filename: str
    game: str | None = None
    version: str | None = None


class ApworldsResponse(BaseModel):
    apworlds: list[ApworldInfo]


class GenerateResponse(BaseModel):
    jobId: str


class GenerationJobResponse(BaseModel):
    jobId: str
    status: str
    startedAt: str | None = None
    finishedAt: str | None = None
    progress: str | None = None
    seed: int | None = None
    seedFile: str | None = None
    spoilerFile: str | None = None
    raceMode: bool | None = None
    error: str | None = None
    message: str | None = None


class ServerInfoResponse(BaseModel):
    running: bool
    handle: str | None
    pid: int | None
    port: int | None
    seedFile: str | None
    startedAt: str | None


class ServerStartResponse(BaseModel):
    ok: bool
    handle: str
    pid: int | None
    port: int


class YamlTemplateResponse(BaseModel):
    game: str
    filename: str
    yaml: str


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
