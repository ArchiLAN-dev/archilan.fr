import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { fetchSlotOwners } from "./reachability-api";

const BASE = TEST_API_BASE_URL;

/**
 * Regression: the goal celebration labelled a slot with the pseudo of whoever was watching it. It
 * now reads the owner from this map, keyed by the Archipelago slot name.
 */
describe("fetchSlotOwners", () => {
  it("maps each slot name to its owner's pseudo", async () => {
    server.use(
      http.get(`${BASE}/sessions/s1/slot-owners`, () =>
        HttpResponse.json({
          data: {
            slots: [
              { slotName: "Alice_TWW", playerNames: ["Alice"] },
              { slotName: "TotallyOther", playerNames: ["Bob"] },
            ],
          },
        }),
      ),
    );

    expect(await fetchSlotOwners("s1")).toEqual({ Alice_TWW: "Alice", TotallyOther: "Bob" });
  });

  it("drops slots whose owner could not be resolved", async () => {
    server.use(
      http.get(`${BASE}/sessions/s1/slot-owners`, () =>
        HttpResponse.json({
          data: { slots: [{ slotName: "Ghost_TWW", playerNames: [] }, { slotName: "Alice_TWW", playerNames: ["Alice"] }] },
        }),
      ),
    );

    // An unnamed slot falls back to the slot name on the card rather than showing an empty title.
    expect(await fetchSlotOwners("s1")).toEqual({ Alice_TWW: "Alice" });
  });

  // Story 16.17: a slot can be played by several people, and naming one of three would be as wrong
  // as naming the viewer.
  it("names a shared slot after all of its players", async () => {
    server.use(
      http.get(`${BASE}/sessions/s1/slot-owners`, () =>
        HttpResponse.json({
          data: {
            slots: [
              { slotName: "Duo_MC", playerNames: ["Alice", "Bob"] },
              { slotName: "Trio_MC", playerNames: ["Alice", "Bob", "Carol"] },
            ],
          },
        }),
      ),
    );

    expect(await fetchSlotOwners("s1")).toEqual({ Duo_MC: "Alice et Bob", Trio_MC: "Alice, Bob et Carol" });
  });

  it("is empty on a malformed payload", async () => {
    server.use(http.get(`${BASE}/sessions/s1/slot-owners`, () => HttpResponse.json({ data: { slots: [{ nope: 1 }] } })));

    expect(await fetchSlotOwners("s1")).toEqual({});
  });

  it("is empty on an error response", async () => {
    server.use(http.get(`${BASE}/sessions/s1/slot-owners`, () => new HttpResponse(null, { status: 403 })));

    expect(await fetchSlotOwners("s1")).toEqual({});
  });
});
