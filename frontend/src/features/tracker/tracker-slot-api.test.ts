import { fetchSessionConnection, type SessionPayload } from "@/features/events/events-api";
import { fetchPersonalRun } from "@/features/personal-runs/personal-runs-api";
import type { PersonalRun } from "@/features/personal-runs/types";
import { fetchCurrentWeeklyRuns, type CurrentWeeklyRun } from "@/features/weekly-runs/weekly-runs-api";

import { fetchSlotConnection } from "./tracker-slot-api";

jest.mock("../events/events-api", () => ({ fetchSessionConnection: jest.fn() }));
jest.mock("../personal-runs/personal-runs-api", () => ({ fetchPersonalRun: jest.fn() }));
jest.mock("../weekly-runs/weekly-runs-api", () => ({ fetchCurrentWeeklyRuns: jest.fn() }));

const eventConnection = jest.mocked(fetchSessionConnection);
const personalRun = jest.mocked(fetchPersonalRun);
const weeklyRuns = jest.mocked(fetchCurrentWeeklyRuns);

/** Story 54.7: the tracker page reads a slot's connection through the endpoints that show it to its player. */
function session(status: string): SessionPayload {
  return { id: "s1", status, host: "archilan.fr", port: 35012, connectionUri: "wss://archilan.fr:35012", password: "pw" };
}

const EVENT_SLOT = {
  slotName: "Jean MM",
  slotOrder: 1,
  gameId: "g-mm",
  gameName: "MM",
  gameSlug: "majoras-mask",
  slotId: "slot-1",
  coPlayed: false,
};

function run(overrides: Partial<PersonalRun>): PersonalRun {
  return {
    id: "r1",
    status: "active",
    connectionUri: "wss://archilan.fr:35020",
    connectionPassword: null,
    mySlots: [{ name: "Jean", game: "MM", gameId: "g-mm", gameSlug: "majoras-mask" }],
    ...overrides,
  } as unknown as PersonalRun;
}

function weekly(overrides: Partial<CurrentWeeklyRun["myEntry"] & object> | null): CurrentWeeklyRun {
  return {
    weeklyRunId: "w1",
    gameSlug: "majoras-mask",
    myEntry:
      overrides === null
        ? null
        : {
            entryId: "e1",
            externalSessionId: "x",
            launchedAt: null,
            goalReachedAt: null,
            sessionStatus: "running",
            slotName: "Player1",
            connectionInfo: { host: "archilan.fr", port: 35030, uri: "wss://archilan.fr:35030", password: "wpw" },
            ...overrides,
          },
  } as unknown as CurrentWeeklyRun;
}

describe("fetchSlotConnection", () => {
  it("connects to an event slot of the player once the party runs", async () => {
    eventConnection.mockResolvedValue({ kind: "success", data: { eventId: "e", session: session("running"), slots: [EVENT_SLOT] } });
    await expect(fetchSlotConnection({ kind: "event", registrationId: "reg", slotName: "Jean MM" })).resolves.toEqual({
      kind: "ready",
      gameSlug: "majoras-mask",
      credentials: { address: "wss://archilan.fr:35012", slot: "Jean MM", password: "pw" },
    });
    expect(eventConnection).toHaveBeenCalledWith("reg");
  });

  it("waits for an event party not launched, refuses another slot and someone else's registration", async () => {
    eventConnection.mockResolvedValue({ kind: "success", data: { eventId: "e", session: session("generated"), slots: [EVENT_SLOT] } });
    await expect(fetchSlotConnection({ kind: "event", registrationId: "reg", slotName: "Jean MM" })).resolves.toEqual({
      kind: "waiting",
      gameSlug: "majoras-mask",
    });
    eventConnection.mockResolvedValue({ kind: "success", data: { eventId: "e", session: null, slots: [] } });
    await expect(fetchSlotConnection({ kind: "event", registrationId: "reg", slotName: "Jean MM" })).resolves.toEqual({
      kind: "waiting",
      gameSlug: null,
    });
    eventConnection.mockResolvedValue({ kind: "success", data: { eventId: "e", session: session("running"), slots: [EVENT_SLOT] } });
    await expect(fetchSlotConnection({ kind: "event", registrationId: "reg", slotName: "Bob" })).resolves.toEqual({ kind: "denied" });
    eventConnection.mockResolvedValue({ kind: "not_found" });
    await expect(fetchSlotConnection({ kind: "event", registrationId: "other", slotName: "Jean MM" })).resolves.toEqual({ kind: "denied" });
    eventConnection.mockResolvedValue({ kind: "error", message: "x" });
    await expect(fetchSlotConnection({ kind: "event", registrationId: "reg", slotName: "Jean MM" })).resolves.toEqual({ kind: "error" });
  });

  it("reads a private party slot, with no password when the party has none", async () => {
    personalRun.mockResolvedValue({ kind: "ready", run: run({}) });
    await expect(fetchSlotConnection({ kind: "run", runId: "r1", slotName: "Jean" })).resolves.toEqual({
      kind: "ready",
      gameSlug: "majoras-mask",
      credentials: { address: "wss://archilan.fr:35020", slot: "Jean", password: "" },
    });
    await expect(fetchSlotConnection({ kind: "run", runId: "r1", slotName: "Bob" })).resolves.toEqual({ kind: "denied" });
    personalRun.mockResolvedValue({ kind: "ready", run: run({ status: "idle", mySlots: [] }) });
    await expect(fetchSlotConnection({ kind: "run", runId: "r1", slotName: "Jean" })).resolves.toEqual({ kind: "waiting", gameSlug: null });
    personalRun.mockResolvedValue({ kind: "not_found" });
    await expect(fetchSlotConnection({ kind: "run", runId: "r1", slotName: "Jean" })).resolves.toEqual({ kind: "denied" });
  });

  it("reads the player's weekly entry", async () => {
    weeklyRuns.mockResolvedValue([weekly({})]);
    await expect(fetchSlotConnection({ kind: "weekly", weeklyRunId: "w1" })).resolves.toEqual({
      kind: "ready",
      gameSlug: "majoras-mask",
      credentials: { address: "wss://archilan.fr:35030", slot: "Player1", password: "wpw" },
    });
    weeklyRuns.mockResolvedValue([weekly({ sessionStatus: "idle" })]);
    await expect(fetchSlotConnection({ kind: "weekly", weeklyRunId: "w1" })).resolves.toEqual({ kind: "waiting", gameSlug: "majoras-mask" });
    weeklyRuns.mockResolvedValue([weekly(null)]);
    await expect(fetchSlotConnection({ kind: "weekly", weeklyRunId: "w1" })).resolves.toEqual({ kind: "denied" });
    await expect(fetchSlotConnection({ kind: "weekly", weeklyRunId: "w2" })).resolves.toEqual({ kind: "denied" });
  });
});
