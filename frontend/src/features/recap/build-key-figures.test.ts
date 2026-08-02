import type { FeedEvent } from "./feed-api";
import type { SessionRecap } from "./recap-api";
import { buildRecapKeyFigures } from "./build-key-figures";

function item(flags: number | null): FeedEvent {
  return {
    id: Math.random().toString(36).slice(2),
    type: "item-received",
    text: "",
    occurredAt: "2026-05-01T10:00:00+00:00",
    item: { id: 1, name: "Item", flags },
    location: { id: 1, name: "Loc" },
    sender: { slot: 1, name: "A", game: "G" },
    receiver: { slot: 2, name: "B", game: "H" },
  };
}

function hint(): FeedEvent {
  return { ...item(null), type: "hint" };
}

function recapWith(podium: Array<{ checksDone: number }>, durationSeconds: number | null): SessionRecap {
  return {
    sessionId: "s",
    eventName: "e",
    startedAt: null,
    finishedAt: null,
    durationSeconds,
    vodUrl: null,
    generatedAt: "2026-05-01T12:00:00+00:00",
    podium: podium.map((slot, i) => ({
      slotId: `slot-${i}`,
      slotName: `Player${i}`,
      playerName: `Player${i}`,
      game: "G",
      checksDone: slot.checksDone,
      itemsReceived: 0,
      goalReachedAt: null,
      completionSeconds: null,
      wasReleased: false,
      isInvalidated: false,
    })),
    graph: { nodes: [], edges: [], localItems: [] },
    superlatives: [],
  };
}

function valueOf(figures: ReturnType<typeof buildRecapKeyFigures>, key: string): string | undefined {
  return figures.find((figure) => figure.key === key)?.value;
}

describe("buildRecapKeyFigures", () => {
  it("truncates the duration exactly like the header does, never rounding past it", () => {
    // 3 h 10 min 50 s: the header shows "3 h 10 min", so this must not read "3 h 11".
    const figures = buildRecapKeyFigures(recapWith([{ checksDone: 1 }], 3 * 3600 + 650), []);

    expect(valueOf(figures, "duration")).toBe("3 h 10");
  });

  it("reports the headline counts", () => {
    const figures = buildRecapKeyFigures(recapWith([{ checksDone: 30 }, { checksDone: 12 }], 3 * 3600 + 600), [
      item(1),
      item(0),
      item(0),
      item(0),
      hint(),
    ]);

    expect(valueOf(figures, "duration")).toBe("3 h 10");
    expect(valueOf(figures, "players")).toBe("2");
    expect(valueOf(figures, "items")).toBe("4");
    expect(valueOf(figures, "checks")).toBe("42");
    expect(valueOf(figures, "hints")).toBe("1");
  });

  it("computes the progression share over item events only", () => {
    const figures = buildRecapKeyFigures(recapWith([{ checksDone: 1 }], 60), [item(1), item(1), item(0), item(0)]);

    expect(valueOf(figures, "progression")).toBe("50 %");
  });

  it("omits the progression share when the feed carries no flag at all", () => {
    // A run recorded before story 32.9: null means unknown, and reporting 0 % would be a lie.
    const figures = buildRecapKeyFigures(recapWith([{ checksDone: 1 }], 60), [item(null), item(null)]);

    expect(valueOf(figures, "progression")).toBeUndefined();
    expect(valueOf(figures, "items")).toBe("2");
  });

  it("still reports the share when only some events carry a flag", () => {
    const figures = buildRecapKeyFigures(recapWith([{ checksDone: 1 }], 60), [item(1), item(null)]);

    expect(valueOf(figures, "progression")).toBe("50 %");
  });

  it("omits every figure it cannot establish", () => {
    const figures = buildRecapKeyFigures(recapWith([], null), []);

    expect(figures).toEqual([]);
  });
});
