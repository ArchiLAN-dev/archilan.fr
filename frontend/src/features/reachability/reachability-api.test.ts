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
              { slotName: "Alice_TWW", playerName: "Alice" },
              { slotName: "TotallyOther", playerName: "Bob" },
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
          data: { slots: [{ slotName: "Ghost_TWW", playerName: "" }, { slotName: "Alice_TWW", playerName: "Alice" }] },
        }),
      ),
    );

    // An unnamed slot falls back to the slot name on the card rather than showing an empty title.
    expect(await fetchSlotOwners("s1")).toEqual({ Alice_TWW: "Alice" });
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
