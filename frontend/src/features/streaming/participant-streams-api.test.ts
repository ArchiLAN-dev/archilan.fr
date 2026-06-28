import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { fetchParticipantStreams } from "./participant-streams-api";

const BASE = TEST_API_BASE_URL;

describe("fetchParticipantStreams", () => {
  it("returns the participants in the order the API provides (live first)", async () => {
    server.use(
      http.get(`${BASE}/events/evt-1/participant-streams`, () =>
        HttpResponse.json({
          data: [
            { userId: "u1", slug: "alice", displayName: "Alice", twitchLogin: "alice", avatarUrl: "https://cdn.test/alice.png", live: true, viewerCount: 42 },
            { userId: "u2", slug: "bob", displayName: null, twitchLogin: "bob", avatarUrl: null, live: false, viewerCount: null },
          ],
        }),
      ),
    );

    const streams = await fetchParticipantStreams("event", "evt-1");

    expect(streams.map((s) => s.twitchLogin)).toEqual(["alice", "bob"]);
    expect(streams[0].live).toBe(true);
    expect(streams[0].viewerCount).toBe(42);
    expect(streams[1].live).toBe(false);
    expect(streams[1].viewerCount).toBeNull();
  });

  it("targets the right path per session kind", async () => {
    server.use(
      http.get(`${BASE}/weekly-runs/wk-1/participant-streams`, () =>
        HttpResponse.json({
          data: [{ userId: "u1", slug: "carol", displayName: "Carol", twitchLogin: "carol", avatarUrl: null, live: true, viewerCount: 3 }],
        }),
      ),
    );

    const streams = await fetchParticipantStreams("weekly", "wk-1");
    expect(streams).toHaveLength(1);
    expect(streams[0].twitchLogin).toBe("carol");
  });

  it("returns [] on a non-200 response", async () => {
    server.use(
      http.get(`${BASE}/runs/run-1/participant-streams`, () => HttpResponse.json({ error: {} }, { status: 404 })),
    );
    expect(await fetchParticipantStreams("run", "run-1")).toEqual([]);
  });

  it("returns [] on a malformed payload", async () => {
    server.use(
      http.get(`${BASE}/events/evt-2/participant-streams`, () => HttpResponse.json({ data: [{ userId: 123 }] })),
    );
    expect(await fetchParticipantStreams("event", "evt-2")).toEqual([]);
  });

  it("returns [] on network error", async () => {
    server.use(http.get(`${BASE}/events/evt-3/participant-streams`, () => HttpResponse.error()));
    expect(await fetchParticipantStreams("event", "evt-3")).toEqual([]);
  });
});
