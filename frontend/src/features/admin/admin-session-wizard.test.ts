import {
  canCreateSession,
  creationPayload,
  generateSlotsFromRegistrations,
  regenerateSlotNames,
  selectionState,
  setAllSelected,
  setSlotSelected,
  validateSlots,
  type WizardRegistration,
} from "./admin-session-wizard";

const registrations: WizardRegistration[] = [
  {
    registrationId: "r1",
    playerName: "Zoé",
    slots: [
      { slotId: "s1", gameId: "g1", gameName: "Zelda", archipelagoGameName: "Ocarina of Time" },
      { slotId: "s2", gameId: "g2", gameName: "Celeste", archipelagoGameName: "Celeste" },
    ],
  },
  { registrationId: "r2", playerName: "Bob", slots: [{ slotId: "s3", gameId: "g3", gameName: "Factorio", archipelagoGameName: "Factorio" }] },
];

describe("slot selection (story 11.9)", () => {
  it("selects every slot at first, as before", () => {
    const slots = generateSlotsFromRegistrations(registrations);
    expect(slots.every((slot) => slot.selected)).toBe(true);
    expect(selectionState(slots)).toBe("all");
    expect(creationPayload(slots).slots.map((slot) => slot.slotId)).toEqual(["s1", "s2", "s3"]);
  });

  it("sends only the selected slots", () => {
    const slots = setSlotSelected(generateSlotsFromRegistrations(registrations), "s2", false);
    expect(selectionState(slots)).toBe("some");
    expect(creationPayload(slots)).toEqual({
      slots: [
        { registrationId: "r1", slotId: "s1", gameId: "g1", slotName: "Zo_OOT" },
        { registrationId: "r2", slotId: "s3", gameId: "g3", slotName: "Bob_F" },
      ],
    });
  });

  it("ignores the errors of a slot left out", () => {
    let slots = generateSlotsFromRegistrations(registrations).map((slot) => (slot.slotId === "s2" ? { ...slot, slotName: "Bob_F" } : slot));
    expect(Object.keys(validateSlots(slots)).sort()).toEqual(["s2", "s3"]);
    expect(canCreateSession(slots)).toBe(false);

    slots = setSlotSelected(slots, "s2", false);
    expect(validateSlots(slots)).toEqual({});
    expect(canCreateSession(slots)).toBe(true);
  });

  it("cannot create a session without a slot", () => {
    const slots = setAllSelected(generateSlotsFromRegistrations(registrations), false);
    expect(selectionState(slots)).toBe("none");
    expect(canCreateSession(slots)).toBe(false);
    expect(selectionState(setAllSelected(slots, true))).toBe("all");
  });

  it("keeps the selection when the names are generated again", () => {
    const slots = setSlotSelected(generateSlotsFromRegistrations(registrations), "s3", false).map((slot) => ({ ...slot, slotName: "x" }));
    const regenerated = regenerateSlotNames(registrations, slots);
    expect(regenerated.map((slot) => [slot.slotId, slot.selected, slot.slotName])).toEqual([
      ["s1", true, "Zo_OOT"],
      ["s2", true, "Zo_C"],
      ["s3", false, "Bob_F"],
    ]);
  });
});
