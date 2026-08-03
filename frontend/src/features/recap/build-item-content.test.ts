import { buildSendQuality, buildTopItems } from "./build-item-content";
import type { FeedEvent } from "./feed-api";

function sent(
  from: number,
  to: number,
  itemName: string | null,
  flags: number | null = 0,
  type = "item-received",
): FeedEvent {
  return {
    id: `${from}-${to}-${itemName}-${Math.random()}`,
    type,
    text: "",
    occurredAt: "2026-05-01T10:00:00Z",
    item: { id: 1, name: itemName, flags },
    location: { id: 1, name: "Loc" },
    sender: { slot: from, name: `P${from}`, game: "G" },
    receiver: { slot: to, name: `P${to}`, game: "H" },
  };
}

describe("buildTopItems", () => {
  it("ranks by count and caps at the limit, reporting how many names exist", () => {
    const top = buildTopItems(
      [sent(1, 2, "Sword"), sent(1, 2, "Sword"), sent(1, 2, "Bomb"), sent(1, 2, "Map")],
      2,
    );

    expect(top.items).toEqual([
      { name: "Sword", count: 2 },
      { name: "Bomb", count: 1 },
    ]);
    expect(top.distinctNames).toBe(3);
  });

  it("breaks ties on the name so the ranking is deterministic", () => {
    const top = buildTopItems([sent(1, 2, "Zelda"), sent(1, 2, "Arrow")], 10);

    expect(top.items.map((item) => item.name)).toEqual(["Arrow", "Zelda"]);
  });

  it("excludes nameless items instead of grouping them under a label", () => {
    const top = buildTopItems([sent(1, 2, null), sent(1, 2, ""), sent(1, 2, "Sword")], 10);

    expect(top.items).toEqual([{ name: "Sword", count: 1 }]);
  });

  it("counts item events only", () => {
    const top = buildTopItems([sent(1, 2, "Sword", 0, "hint")], 10);

    expect(top.items).toEqual([]);
  });
});

describe("buildSendQuality", () => {
  it("classifies each send by its AP flag bits", () => {
    const quality = buildSendQuality([
      sent(1, 2, "a", 1), // progression
      sent(1, 2, "b", 3), // progression (bit 1 wins over bit 2)
      sent(1, 2, "c", 2), // useful
      sent(1, 2, "d", 4), // trap
      sent(1, 2, "e", 0), // filler
      sent(1, 2, "f", null), // unknown, never folded into filler
    ]);

    expect(quality[0]).toMatchObject({
      progression: 2,
      useful: 1,
      trap: 1,
      filler: 1,
      unknown: 1,
      total: 6,
    });
  });

  it("counts sends to others only, never a slot's own finds", () => {
    const quality = buildSendQuality([sent(1, 1, "own", 1), sent(1, 2, "given", 1)]);

    expect(quality).toHaveLength(1);
    expect(quality[0].total).toBe(1);
  });

  it("orders players by progression sent", () => {
    const quality = buildSendQuality([
      sent(1, 2, "a", 0),
      sent(2, 1, "b", 1),
      sent(2, 1, "c", 1),
    ]);

    expect(quality.map((entry) => entry.slot)).toEqual([2, 1]);
  });

  it("returns nothing for a feed with no cross-player send", () => {
    expect(buildSendQuality([])).toEqual([]);
  });
});
