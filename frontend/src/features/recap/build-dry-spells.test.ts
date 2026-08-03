import { buildDrySpells } from "./build-dry-spells";
import type { FeedEvent } from "./feed-api";

function received(slot: number, name: string, iso: string, type = "item-received"): FeedEvent {
  return {
    id: `${slot}-${iso}`,
    type,
    text: "",
    occurredAt: iso,
    item: { id: 1, name: "Item", flags: 0 },
    location: { id: 1, name: "Loc" },
    sender: { slot: 99, name: "someone", game: "G" },
    receiver: { slot, name, game: "H" },
  };
}

describe("buildDrySpells", () => {
  it("returns the longest gap between two consecutive receptions", () => {
    const spells = buildDrySpells([
      received(1, "Alice", "2026-05-01T10:00:00Z"),
      received(1, "Alice", "2026-05-01T10:05:00Z"), // 5 min
      received(1, "Alice", "2026-05-01T10:35:00Z"), // 30 min <- longest
      received(1, "Alice", "2026-05-01T10:40:00Z"), // 5 min
    ]);

    expect(spells).toHaveLength(1);
    expect(spells[0].seconds).toBe(30 * 60);
    expect(spells[0].from).toBe(Date.parse("2026-05-01T10:05:00Z"));
    expect(spells[0].to).toBe(Date.parse("2026-05-01T10:35:00Z"));
  });

  it("ignores what happened before the first and after the last reception", () => {
    // One reception at each end of a long run: no gap between two receptions, so nothing to report.
    const spells = buildDrySpells([received(1, "Alice", "2026-05-01T10:00:00Z")]);

    expect(spells).toEqual([]);
  });

  it("keys on the receiver, never on the sender", () => {
    const spells = buildDrySpells([
      received(1, "Alice", "2026-05-01T10:00:00Z"),
      received(1, "Alice", "2026-05-01T11:00:00Z"),
    ]);

    expect(spells[0].slot).toBe(1);
    expect(spells[0].name).toBe("Alice");
  });

  it("counts item events only - a hint is not a reception", () => {
    const spells = buildDrySpells([
      received(1, "Alice", "2026-05-01T10:00:00Z"),
      received(1, "Alice", "2026-05-01T10:30:00Z", "hint"),
      received(1, "Alice", "2026-05-01T11:00:00Z"),
    ]);

    expect(spells[0].seconds).toBe(60 * 60);
  });

  it("sorts players by descending dry spell", () => {
    const spells = buildDrySpells([
      received(1, "Alice", "2026-05-01T10:00:00Z"),
      received(1, "Alice", "2026-05-01T10:10:00Z"),
      received(2, "Bob", "2026-05-01T10:00:00Z"),
      received(2, "Bob", "2026-05-01T11:00:00Z"),
    ]);

    expect(spells.map((spell) => spell.name)).toEqual(["Bob", "Alice"]);
  });

  it("tolerates events arriving out of order", () => {
    const spells = buildDrySpells([
      received(1, "Alice", "2026-05-01T11:00:00Z"),
      received(1, "Alice", "2026-05-01T10:00:00Z"),
    ]);

    expect(spells[0].seconds).toBe(60 * 60);
  });
});
