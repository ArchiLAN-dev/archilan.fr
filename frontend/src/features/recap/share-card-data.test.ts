import type { RecapPodiumSlot, SessionRecap } from "./recap-api";
import { buildShareCardData } from "./share-card-data";

function podiumSlot(overrides: Partial<RecapPodiumSlot> = {}): RecapPodiumSlot {
  return {
    slotId: "slot-1",
    slotName: "Michel",
    playerName: "Michel_M",
    game: "Super Mario 64",
    checksDone: 120,
    itemsReceived: 80,
    goalReachedAt: "2026-07-12T21:00:00+00:00",
    completionSeconds: 5400,
    wasReleased: false,
    isInvalidated: false,
    ...overrides,
  };
}

function recap(overrides: Partial<SessionRecap> = {}): SessionRecap {
  return {
    sessionId: "s-1",
    eventName: "ArchiLAN #2",
    startedAt: "2026-07-12T18:00:00+00:00",
    finishedAt: "2026-07-12T23:00:00+00:00",
    durationSeconds: 4 * 3600 + 30 * 60,
    vodUrl: null,
    generatedAt: "2026-07-13T00:00:00+00:00",
    podium: [
      podiumSlot({ slotId: "slot-1", playerName: "Michel_M" }),
      podiumSlot({ slotId: "slot-2", playerName: "Sarah", game: "The Wind Waker", completionSeconds: 7200 }),
      podiumSlot({ slotId: "slot-3", playerName: "Karim", game: "Luigi's Mansion", completionSeconds: null, goalReachedAt: null }),
      podiumSlot({ slotId: "slot-4", playerName: "Léa", game: "OoT" }),
    ],
    graph: {
      nodes: [
        { slotId: "slot-1", slotName: "Michel", game: "Super Mario 64" },
        { slotId: "slot-2", slotName: "SarahYaml", game: "The Wind Waker" },
      ],
      edges: [],
      localItems: [],
    },
    superlatives: [
      { key: "most_generous", label: "Le Parrain des objets", slotId: "slot-2", value: 42 },
      { key: "first_to_goal", label: "Premier arrivé", slotId: "slot-1", value: "2026-07-12T21:00:00+00:00" },
    ],
    ...overrides,
  };
}

describe("buildShareCardData", () => {
  it("returns a fallback card for a null recap", () => {
    expect(buildShareCardData(null)).toEqual({ kind: "fallback" });
  });

  it("keeps only the podium top 3, ranked, with formatted times", () => {
    const data = buildShareCardData(recap());
    if (data.kind !== "recap") throw new Error("expected recap card");
    expect(data.podium).toHaveLength(3);
    expect(data.podium[0]).toEqual({ rank: 1, playerName: "Michel_M", game: "Super Mario 64", time: "1 h 30 min" });
    expect(data.podium[1].rank).toBe(2);
    expect(data.podium[1].time).toBe("2 h 00 min");
  });

  it("uses a placeholder time when completionSeconds is null", () => {
    const data = buildShareCardData(recap());
    if (data.kind !== "recap") throw new Error("expected recap card");
    expect(data.podium[2]).toEqual({ rank: 3, playerName: "Karim", game: "Luigi's Mansion", time: "-" });
  });

  it("renders a smaller podium as-is", () => {
    const data = buildShareCardData(recap({ podium: [podiumSlot()] }));
    if (data.kind !== "recap") throw new Error("expected recap card");
    expect(data.podium).toHaveLength(1);
  });

  it("picks the first superlative and resolves the winner name from the podium", () => {
    const data = buildShareCardData(recap());
    if (data.kind !== "recap") throw new Error("expected recap card");
    expect(data.headline).toEqual({ label: "Le Parrain des objets", playerName: "Sarah" });
  });

  it("falls back to the graph node slotName when the winner is not on the podium", () => {
    const data = buildShareCardData(
      recap({
        superlatives: [{ key: "biggest_hub", label: "La Plaque tournante", slotId: "slot-9", value: 7 }],
        graph: {
          nodes: [{ slotId: "slot-9", slotName: "Ghost", game: "OoT" }],
          edges: [],
          localItems: [],
        },
      }),
    );
    if (data.kind !== "recap") throw new Error("expected recap card");
    expect(data.headline).toEqual({ label: "La Plaque tournante", playerName: "Ghost" });
  });

  it("drops the headline when there is no superlative or no resolvable winner", () => {
    const noSuperlatives = buildShareCardData(recap({ superlatives: [] }));
    if (noSuperlatives.kind !== "recap") throw new Error("expected recap card");
    expect(noSuperlatives.headline).toBeNull();

    const unresolvable = buildShareCardData(
      recap({
        superlatives: [{ key: "most_generous", label: "Le Parrain des objets", slotId: "slot-404", value: 1 }],
        graph: { nodes: [], edges: [], localItems: [] },
      }),
    );
    if (unresolvable.kind !== "recap") throw new Error("expected recap card");
    expect(unresolvable.headline).toBeNull();
  });

  it("exposes event name, player count and formatted duration", () => {
    const data = buildShareCardData(recap());
    if (data.kind !== "recap") throw new Error("expected recap card");
    expect(data.eventName).toBe("ArchiLAN #2");
    expect(data.playerCount).toBe(4);
    expect(data.duration).toBe("4 h 30 min");
  });

  it("leaves the duration null when the session has none", () => {
    const data = buildShareCardData(recap({ durationSeconds: null }));
    if (data.kind !== "recap") throw new Error("expected recap card");
    expect(data.duration).toBeNull();
  });
});
