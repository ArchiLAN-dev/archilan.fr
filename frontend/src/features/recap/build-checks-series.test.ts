import { buildChecksSeries } from "./build-checks-series";
import type { FeedEvent } from "./feed-api";

function itemEvent(id: string, senderSlot: number, senderName: string, occurredAt: string): FeedEvent {
  return {
    id,
    type: "item-received",
    text: "",
    occurredAt,
    item: { id: 1, name: "Key" },
    location: { id: 2, name: "Chest" },
    sender: { slot: senderSlot, name: senderName, game: "Game" },
    receiver: { slot: senderSlot, name: senderName, game: "Game" },
  };
}

describe("buildChecksSeries", () => {
  it("returns empty when there are no item events with a finder", () => {
    expect(buildChecksSeries([])).toEqual({ players: [], rows: [] });
  });

  it("builds cumulative per-player curves bucketed by minute", () => {
    const series = buildChecksSeries([
      itemEvent("a", 1, "Alice", "2026-05-01T10:00:00Z"),
      itemEvent("b", 1, "Alice", "2026-05-01T10:00:30Z"),
      itemEvent("c", 2, "Bob", "2026-05-01T10:00:30Z"),
      itemEvent("d", 1, "Alice", "2026-05-01T10:01:30Z"),
    ]);

    // Minute 0 holds Alice's first two finds and Bob's one; minute 1 adds Alice's third. Cumulative.
    expect(series.rows).toEqual([
      { minute: 0, s1: 2, s2: 1 },
      { minute: 1, s1: 3, s2: 1 },
    ]);
  });

  it("keys and colours players by slot order, not by rank", () => {
    // Bob (slot 2) finds first, but colours follow the slot, so slot 1 keeps series-1.
    const series = buildChecksSeries([
      itemEvent("a", 2, "Bob", "2026-05-01T10:00:00Z"),
      itemEvent("b", 1, "Alice", "2026-05-01T10:00:10Z"),
    ]);

    expect(series.players).toEqual([
      { key: "s1", slot: 1, name: "Alice", color: "var(--chart-series-1)" },
      { key: "s2", slot: 2, name: "Bob", color: "var(--chart-series-2)" },
    ]);
  });
});
