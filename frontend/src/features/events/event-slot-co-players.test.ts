import { http, HttpResponse } from "msw";

import { server } from "@/tests/setup";
import { TEST_API_BASE_URL } from "@/tests/constants";
import { fetchEventSlotCoPlayers, parseEventSlotCoPlayersView, replaceEventSlotCoPlayers } from "./event-slot-co-players-api";

const bob = { userId: "u-bob", displayName: "Bob" };

/** Story 11.10: the co-players of a slot of an event. */
describe("event slot co-players", () => {
  test("reads the roster, the candidates and the right to manage", () => {
    expect(parseEventSlotCoPlayersView({ data: { coPlayers: [bob], candidates: [], canManage: false } })).toEqual({
      coPlayers: [bob],
      candidates: [],
      canManage: false,
    });
  });

  test("refuses a malformed payload", () => {
    expect(parseEventSlotCoPlayersView({ data: { coPlayers: [{ userId: 1 }], candidates: [], canManage: true } })).toBeNull();
    expect(parseEventSlotCoPlayersView({ data: { coPlayers: [], candidates: [] } })).toBeNull();
    expect(parseEventSlotCoPlayersView(null)).toBeNull();
  });

  test("hides the roster from whoever may not see it", async () => {
    server.use(http.get(`${TEST_API_BASE_URL}/events/e1/slots/s1/co-players`, () => HttpResponse.json({ error: {} }, { status: 403 })));
    await expect(fetchEventSlotCoPlayers("e1", "s1")).resolves.toBeNull();
  });

  test("sends the whole list on save", async () => {
    let body: unknown = null;
    server.use(
      http.put(`${TEST_API_BASE_URL}/events/e1/slots/s1/co-players`, async ({ request }) => {
        body = await request.json();
        return HttpResponse.json({ data: { coPlayers: [bob] } });
      }),
    );
    await expect(replaceEventSlotCoPlayers("e1", "s1", ["u-bob"])).resolves.toBe(true);
    expect(body).toEqual({ userIds: ["u-bob"] });
  });
});
