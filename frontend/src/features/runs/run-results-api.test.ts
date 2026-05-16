import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { getRunResults } from "./run-results-api";

const BASE = TEST_API_BASE_URL;

const validSlot = {
  slotId: "slot-1",
  playerName: "jean",
  game: "alttp",
  checksDone: 50,
  itemsReceived: 30,
  goalReachedAt: null,
  completionSeconds: null,
  wasReleased: false,
  isInvalidated: false,
};

describe("getRunResults", () => {
  it("returns run results on success", async () => {
    server.use(
      http.get(`${BASE}/runs/run-abc/results`, () =>
        HttpResponse.json({
          data: {
            sessionId: "run-abc",
            eventName: "LAN 2024",
            startedAt: "2024-06-01T10:00:00Z",
            finishedAt: null,
            durationSeconds: null,
            slots: [validSlot],
          },
        }),
      ),
    );
    const result = await getRunResults("run-abc");
    expect(result).not.toBeNull();
    expect(result?.sessionId).toBe("run-abc");
    expect(result?.slots).toHaveLength(1);
  });

  it("returns null on network error", async () => {
    server.use(http.get(`${BASE}/runs/run-err/results`, () => HttpResponse.error()));
    expect(await getRunResults("run-err")).toBeNull();
  });

  it("returns null when response fails type guard", async () => {
    server.use(
      http.get(`${BASE}/runs/run-bad/results`, () => HttpResponse.json({ wrong: true })),
    );
    expect(await getRunResults("run-bad")).toBeNull();
  });

  it("returns null on non-OK response", async () => {
    server.use(
      http.get(`${BASE}/runs/run-500/results`, () => new HttpResponse(null, { status: 404 })),
    );
    expect(await getRunResults("run-500")).toBeNull();
  });
});
