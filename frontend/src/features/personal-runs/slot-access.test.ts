import { isReadOnlySlot, switcherSlots } from "./slot-access";

describe("an event slot's page, read-only for another player's slot (story 53.9)", () => {
  it("lets the player act on their own or co-played slot", () => {
    expect(isReadOnlySlot("event", false, ["Alice", "Shared"], "Shared")).toBe(false);
  });

  it("reads another player's slot without acting on it", () => {
    expect(isReadOnlySlot("event", false, ["Alice"], "Bob")).toBe(true);
  });

  it("shows nothing that writes until the slot is known", () => {
    expect(isReadOnlySlot("event", false, undefined, "Alice")).toBe(true);
    expect(isReadOnlySlot("event", false, ["Alice"], null)).toBe(true);
  });

  it("leaves an admin and a private run as they were", () => {
    expect(isReadOnlySlot("event", true, ["Alice"], "Bob")).toBe(false);
    expect(isReadOnlySlot("run", false, undefined, "Bob")).toBe(false);
  });

  it("offers every slot of the game, the player's own first, without the bridge", () => {
    const slots = { "1": { slot_name: "Bridge" }, "2": { slot_name: "Bob" }, "3": { slot_name: "Carol" }, "10": { slot_name: "Alice" } };
    expect(switcherSlots(slots, ["Alice"])).toEqual([
      { index: "10", name: "Alice", own: true },
      { index: "2", name: "Bob", own: false },
      { index: "3", name: "Carol", own: false },
    ]);
    expect(switcherSlots(slots, undefined).map((slot) => slot.index)).toEqual(["2", "3", "10"]);
  });
});
