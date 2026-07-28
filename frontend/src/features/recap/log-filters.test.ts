import type { FeedEvent } from "./feed-api";
import { matchesFacet, matchesSearch, normalizeSearch } from "./log-filters";

function event(senderSlot: number | null, receiverSlot: number | null, itemName: string | null, locationName: string | null): FeedEvent {
  return {
    id: "e",
    type: "item-received",
    text: "",
    occurredAt: "2026-05-01T10:00:00Z",
    item: { id: 1, name: itemName, flags: null },
    location: { id: 2, name: locationName },
    sender: { slot: senderSlot, name: "S", game: "G" },
    receiver: { slot: receiverSlot, name: "R", game: "G" },
  };
}

describe("normalizeSearch", () => {
  it("lowercases and strips accents", () => {
    expect(normalizeSearch("Épée Maîtresse")).toBe("epee maitresse");
    expect(normalizeSearch("château")).toBe("chateau");
  });
});

describe("matchesSearch", () => {
  it("matches an accented item name from an unaccented query, and the reverse", () => {
    const e = event(1, 2, "Épée Maîtresse", null);
    expect(matchesSearch(e, normalizeSearch("epee"))).toBe(true);
    expect(matchesSearch(event(1, 2, "Epee", null), normalizeSearch("épée"))).toBe(true);
  });

  it("matches on the location name too, and rejects a non-match", () => {
    const e = event(1, 2, "Key", "Château de Bowser");
    expect(matchesSearch(e, normalizeSearch("chateau"))).toBe(true);
    expect(matchesSearch(e, normalizeSearch("triforce"))).toBe(false);
  });

  it("matches everything on an empty query, even null names", () => {
    expect(matchesSearch(event(1, 2, null, null), "")).toBe(true);
    expect(matchesSearch(event(1, 2, null, null), normalizeSearch("key"))).toBe(false);
  });
});

describe("matchesFacet", () => {
  const shown = new Set([1]);

  it('"all" keeps everything', () => {
    expect(matchesFacet(event(1, 1, null, null), "all", shown)).toBe(true);
    expect(matchesFacet(event(2, 3, null, null), "all", shown)).toBe(true);
  });

  it('"local" keeps only self-finds', () => {
    expect(matchesFacet(event(1, 1, null, null), "local", shown)).toBe(true);
    expect(matchesFacet(event(1, 2, null, null), "local", shown)).toBe(false);
  });

  it('"sent" keeps transfers a shown player made for someone else', () => {
    expect(matchesFacet(event(1, 2, null, null), "sent", shown)).toBe(true);
    expect(matchesFacet(event(2, 1, null, null), "sent", shown)).toBe(false);
    expect(matchesFacet(event(1, 1, null, null), "sent", shown)).toBe(false);
  });

  it('"received" keeps transfers a shown player got from someone else', () => {
    expect(matchesFacet(event(2, 1, null, null), "received", shown)).toBe(true);
    expect(matchesFacet(event(1, 2, null, null), "received", shown)).toBe(false);
    expect(matchesFacet(event(1, 1, null, null), "received", shown)).toBe(false);
  });
});
