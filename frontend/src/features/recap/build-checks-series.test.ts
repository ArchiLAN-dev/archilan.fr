import { buildChecksSeries } from "./build-checks-series";
import type { FeedEvent } from "./feed-api";

const START = Date.parse("2026-05-01T10:00:00Z");

function itemEvent(id: string, senderSlot: number, senderName: string, occurredAt: string, flags: number | null = null): FeedEvent {
  return {
    id,
    type: "item-received",
    text: "",
    occurredAt,
    item: { id: 1, name: "Key", flags },
    location: { id: 2, name: "Chest" },
    sender: { slot: senderSlot, name: senderName, game: "Game" },
    receiver: { slot: senderSlot, name: senderName, game: "Game" },
  };
}

describe("buildChecksSeries", () => {
  it("returns empty when there are no item events with a finder", () => {
    expect(buildChecksSeries([])).toEqual({ players: [], rows: [] });
  });

  it("counts checks per player per minute (default bucket), 0 in a quiet minute", () => {
    const series = buildChecksSeries([
      itemEvent("a", 1, "Alice", "2026-05-01T10:00:00Z"),
      itemEvent("b", 1, "Alice", "2026-05-01T10:00:30Z"),
      itemEvent("c", 2, "Bob", "2026-05-01T10:00:30Z"),
      itemEvent("d", 1, "Alice", "2026-05-01T10:01:30Z"),
    ]);

    // t = the bucket start as a wall-clock epoch. Minute 0: Alice 2, Bob 1. Minute 1: Alice 1, Bob 0.
    // Unflagged (legacy) events count 0 progression finds.
    expect(series.rows).toEqual([
      { t: START, s1: 2, s1p: 0, s2: 1, s2p: 0 },
      { t: START + 60_000, s1: 1, s1p: 0, s2: 0, s2p: 0 },
    ]);
  });

  it("counts progression finds (AP flags bit 1) per player per bucket", () => {
    const series = buildChecksSeries([
      itemEvent("a", 1, "Alice", "2026-05-01T10:00:00Z", 1),
      itemEvent("b", 1, "Alice", "2026-05-01T10:00:30Z", 2), // useful, not progression
      itemEvent("c", 2, "Bob", "2026-05-01T10:00:30Z", 3), // progression + useful
      itemEvent("d", 1, "Alice", "2026-05-01T10:01:30Z", 0),
    ]);

    expect(series.rows).toEqual([
      { t: START, s1: 2, s1p: 1, s2: 1, s2p: 1 },
      { t: START + 60_000, s1: 1, s1p: 0, s2: 0, s2p: 0 },
    ]);
  });

  it("supports a finer bucket, splitting a minute into sub-buckets", () => {
    const series = buildChecksSeries(
      [
        itemEvent("a", 1, "Alice", "2026-05-01T10:00:00Z"),
        itemEvent("b", 1, "Alice", "2026-05-01T10:00:30Z"),
      ],
      10,
    );

    // 10 s buckets: a find at 0 s, one at 30 s, empty buckets between stay 0.
    expect(series.rows).toEqual([
      { t: START, s1: 1, s1p: 0 },
      { t: START + 10_000, s1: 0, s1p: 0 },
      { t: START + 20_000, s1: 0, s1p: 0 },
      { t: START + 30_000, s1: 1, s1p: 0 },
    ]);
  });

  it("keys and colours players by slot order, not by rank", () => {
    // Bob (slot 2) finds first, but colours follow the slot, so slot 1 keeps series-1.
    const series = buildChecksSeries([
      itemEvent("a", 2, "Bob", "2026-05-01T10:00:00Z"),
      itemEvent("b", 1, "Alice", "2026-05-01T10:00:10Z"),
    ]);

    expect(series.players).toEqual([
      { key: "s1", slot: 1, name: "Alice", color: "var(--chart-series-1)", progressionKey: "s1p" },
      { key: "s2", slot: 2, name: "Bob", color: "var(--chart-series-2)", progressionKey: "s2p" },
    ]);
  });
});
