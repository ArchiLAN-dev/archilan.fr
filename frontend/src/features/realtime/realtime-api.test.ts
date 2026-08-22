import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { reconnectWithFreshToken } from "./realtime-api";

const BASE = TEST_API_BASE_URL;

const CURRENT = { token: "stale-token", hubUrl: "https://hub.test/.well-known/mercure", topic: "runs/r1/players" };

function flush(): Promise<void> {
  return new Promise((resolve) => { setTimeout(resolve, 0); });
}

/**
 * Regression: the subscribe JWT lives one hour and EventSource gives up for good on a 401, so
 * reconnecting with the token of the first connection turned every drop past that hour into an
 * unrecoverable loop - the progression page kept reopening a stream the hub had already rejected,
 * and only a reload recovered.
 */
describe("reconnectWithFreshToken", () => {
  it("reconnects with a freshly minted token", async () => {
    server.use(
      http.get(`${BASE}/sessions/r1/players-token`, () =>
        HttpResponse.json({ data: { token: "fresh-token", hubUrl: "https://hub.test/next", topic: "runs/r1/players" } }),
      ),
    );
    const calls: string[][] = [];

    reconnectWithFreshToken("/sessions/r1/players-token", CURRENT, (t, h, topic) => { calls.push([t, h, topic]); });
    await flush();

    expect(calls).toEqual([["fresh-token", "https://hub.test/next", "runs/r1/players"]]);
  });

  it("falls back to the current token when re-minting fails", async () => {
    server.use(http.get(`${BASE}/sessions/r1/players-token`, () => new HttpResponse(null, { status: 503 })));
    const calls: string[][] = [];

    reconnectWithFreshToken("/sessions/r1/players-token", CURRENT, (t, h, topic) => { calls.push([t, h, topic]); });
    await flush();

    // A transient API blip must still get its reconnection attempt, not kill the stream.
    expect(calls).toEqual([[CURRENT.token, CURRENT.hubUrl, CURRENT.topic]]);
  });

  it("does not reconnect once the effect has been torn down", async () => {
    server.use(
      http.get(`${BASE}/sessions/r1/players-token`, () =>
        HttpResponse.json({ data: { token: "fresh-token", hubUrl: "https://hub.test/next", topic: "runs/r1/players" } }),
      ),
    );
    const calls: string[][] = [];

    reconnectWithFreshToken("/sessions/r1/players-token", CURRENT, (t, h, topic) => { calls.push([t, h, topic]); }, () => true);
    await flush();

    expect(calls).toEqual([]);
  });
});
