import { isRealPlayerSlot } from "./overlay-api";

/** Story 9.56: only the slot type decides who is a real player in the overlay filter. */
describe("isRealPlayerSlot", () => {
  it("keeps a player, even one who plays the Archipelago world", () => {
    expect(isRealPlayerSlot({ slot_name: "Alice", game: "Archipelago", slot_type: "player" })).toBe(true);
  });

  it("leaves out the observer of an older multiworld and item-link groups", () => {
    expect(isRealPlayerSlot({ slot_name: "Bridge", game: "Archipelago", slot_type: "spectator" })).toBe(false);
    expect(isRealPlayerSlot({ slot_name: "Link", game: "A Link to the Past", slot_type: "group" })).toBe(false);
  });

  it("keeps a slot whose type is unknown rather than hide it", () => {
    expect(isRealPlayerSlot({ slot_name: "Alice" })).toBe(true);
  });
});
