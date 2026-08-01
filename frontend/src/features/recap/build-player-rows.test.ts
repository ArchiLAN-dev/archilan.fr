import { buildPlayerRows } from "./build-player-rows";
import type { SessionRecap } from "./recap-api";

type SlotSeed = {
  slotId: string;
  slotName: string;
  playerName: string;
  checksDone?: number;
  completionSeconds?: number | null;
  isInvalidated?: boolean;
  wasReleased?: boolean;
};

function recap(
  slots: SlotSeed[],
  graph: Partial<SessionRecap["graph"]> = {},
  superlatives: SessionRecap["superlatives"] = [],
): SessionRecap {
  return {
    sessionId: "s",
    eventName: "e",
    startedAt: null,
    finishedAt: null,
    durationSeconds: null,
    vodUrl: null,
    generatedAt: "2026-05-01T12:00:00+00:00",
    podium: slots.map((slot) => ({
      slotId: slot.slotId,
      slotName: slot.slotName,
      playerName: slot.playerName,
      game: "G",
      checksDone: slot.checksDone ?? 0,
      itemsReceived: 0,
      goalReachedAt: null,
      completionSeconds: slot.completionSeconds ?? null,
      wasReleased: slot.wasReleased ?? false,
      isInvalidated: slot.isInvalidated ?? false,
    })),
    graph: { nodes: [], edges: [], localItems: [], ...graph },
    superlatives,
  };
}

describe("buildPlayerRows", () => {
  it("counts exchanges with others and keeps own finds in their own column", () => {
    const rows = buildPlayerRows(
      recap([
        { slotId: "a", slotName: "LM", playerName: "jean" },
        { slotId: "b", slotName: "P", playerName: "marie" },
      ], {
        edges: [
          { fromSlotId: "a", toSlotId: "b", count: 54 },
          { fromSlotId: "b", toSlotId: "a", count: 42 },
        ],
        localItems: [
          { slotId: "a", count: 28 },
          { slotId: "b", count: 67 },
        ],
      }),
    );

    expect(rows[0]).toMatchObject({ sentToOthers: 54, receivedFromOthers: 42, kept: 28 });
    expect(rows[1]).toMatchObject({ sentToOthers: 42, receivedFromOthers: 54, kept: 67 });
  });

  it("disambiguates a player holding several slots, and leaves a unique name alone", () => {
    const rows = buildPlayerRows(
      recap([
        { slotId: "a", slotName: "masterkafey_LM", playerName: "masterkafey" },
        { slotId: "b", slotName: "masterkafey_P", playerName: "masterkafey" },
        { slotId: "c", slotName: "solo", playerName: "marie" },
      ]),
    );

    expect(rows.map((row) => row.label)).toEqual([
      "masterkafey (masterkafey_LM)",
      "masterkafey (masterkafey_P)",
      "marie",
    ]);
  });

  it("ranks only finished, non-invalidated slots and keeps podium order", () => {
    const rows = buildPlayerRows(
      recap([
        { slotId: "a", slotName: "A", playerName: "a", completionSeconds: 100 },
        { slotId: "b", slotName: "B", playerName: "b", completionSeconds: 200, isInvalidated: true },
        { slotId: "c", slotName: "C", playerName: "c", completionSeconds: 300 },
        { slotId: "d", slotName: "D", playerName: "d", completionSeconds: null },
      ]),
    );

    expect(rows.map((row) => row.rank)).toEqual([1, null, 2, null]);
  });

  it("attaches every superlative to its slot", () => {
    const rows = buildPlayerRows(
      recap([{ slotId: "a", slotName: "A", playerName: "a" }], {}, [
        { key: "most_generous", label: "Le Parrain", slotId: "a", value: 54 },
        { key: "first_to_goal", label: "Speedy Gonzales", slotId: "a", value: "2026-05-01T23:00:00+00:00" },
        { key: "orphan", label: "Personne", slotId: "zzz", value: 1 },
      ]),
    );

    expect(rows[0].badges.map((badge) => badge.key)).toEqual(["most_generous", "first_to_goal"]);
  });

  it("defaults every aggregate to zero for a slot absent from the graph", () => {
    const rows = buildPlayerRows(recap([{ slotId: "a", slotName: "A", playerName: "a", checksDone: 7 }]));

    expect(rows[0]).toMatchObject({ sentToOthers: 0, receivedFromOthers: 0, kept: 0, checksDone: 7 });
  });
});
