import { runConnectionSlots } from "@/features/personal-runs/run-connection-slots";
import { weeklyConnectionSlots } from "@/features/weekly-runs/weekly-connection-slots";

import { formatSlotRef, parseSlotRef, slotTracker, trackerSlotHref, type SlotRef } from "./slot-ref";

describe("slot references (story 54.7)", () => {
  it.each<[SlotRef, string]>([
    [{ kind: "event", registrationId: "reg-1", slotName: "Jean: MM #2" }, "event:reg-1:Jean%3A%20MM%20%232"],
    [{ kind: "run", runId: "run-9", slotName: "Alice" }, "run:run-9:Alice"],
    [{ kind: "weekly", weeklyRunId: "wr-3" }, "weekly:wr-3"],
  ])("writes and reads back %j", (ref, text) => {
    expect(formatSlotRef(ref)).toBe(text);
    expect(parseSlotRef(text)).toEqual(ref);
  });

  it("refuses what is not a slot reference", () => {
    for (const text of [null, "", "event", "event:reg-1", "event:reg-1:", "weekly:wr:x", "game:1:x", "event:../x:a", "run:r:%E0%A4%A"]) {
      expect(parseSlotRef(text)).toBeNull();
    }
  });

  it("links to the game's tracker with the slot, and no secret", () => {
    expect(trackerSlotHref("majoras-mask", { kind: "run", runId: "r1", slotName: "Jean MM" })).toBe(
      "/jeux/majoras-mask/tracker?slot=run%3Ar1%3AJean%2520MM",
    );
  });

  it("gives no tracker to a slot without a catalogue game", () => {
    const ref: SlotRef = { kind: "weekly", weeklyRunId: "w" };
    expect(slotTracker(null, "x", ref)).toBeUndefined();
    expect(slotTracker("g", undefined, ref)).toBeUndefined();
    expect(slotTracker("g", "x", ref)).toEqual({ gameId: "g", gameSlug: "x", ref });
  });

  it("builds the slots of a private party and of a weekly run", () => {
    expect(
      runConnectionSlots({
        id: "r1",
        mySlots: [
          { name: "Jean", game: "MM", gameId: "g1", gameSlug: "mm" },
          { name: "Imported", game: "Old", gameId: null, gameSlug: null },
        ],
      }),
    ).toEqual([
      { name: "Jean", game: "MM", tracker: { gameId: "g1", gameSlug: "mm", ref: { kind: "run", runId: "r1", slotName: "Jean" } } },
      { name: "Imported", game: "Old", tracker: undefined },
    ]);
    expect(weeklyConnectionSlots({ weeklyRunId: "w1", gameId: "g1", gameSlug: "mm" }, { slotName: "Player1" })).toEqual([
      { name: "Player1", game: null, tracker: { gameId: "g1", gameSlug: "mm", ref: { kind: "weekly", weeklyRunId: "w1" } } },
    ]);
    expect(weeklyConnectionSlots({ weeklyRunId: "w1" }, { slotName: null })).toEqual([]);
  });
});
