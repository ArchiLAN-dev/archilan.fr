import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { getGameYamlTest, startGameYamlTest } from "./game-yaml-test-api";

const BASE = TEST_API_BASE_URL;

describe("startGameYamlTest", () => {
  it("sends the YAML and returns the ticket", async () => {
    let sent: unknown = null;
    server.use(
      http.post(`${BASE}/games/game-hk/yaml-test`, async ({ request }) => {
        sent = await request.json();
        return HttpResponse.json({ data: { ticket: "job.sig" } }, { status: 202 });
      }),
    );

    expect(await startGameYamlTest("game-hk", "name: Jean\n")).toEqual({ ok: true, ticket: "job.sig" });
    expect(sent).toEqual({ yaml: "name: Jean\n" });
  });

  it("returns the API's message on a refusal", async () => {
    server.use(
      http.post(`${BASE}/games/game-hk/yaml-test`, () =>
        HttpResponse.json({ error: { code: "too_many_tests", message: "Tu as lancé 5 tests en 10 minutes." } }, { status: 429 }),
      ),
    );

    expect(await startGameYamlTest("game-hk", "name: Jean\n")).toEqual({ ok: false, message: "Tu as lancé 5 tests en 10 minutes." });
  });

  it("returns a generic message on a network error", async () => {
    server.use(http.post(`${BASE}/games/game-hk/yaml-test`, () => HttpResponse.error()));

    expect(await startGameYamlTest("game-hk", "name: Jean\n")).toEqual({ ok: false, message: "Le test n'a pas pu être lancé. Réessaie dans un instant." });
  });
});

describe("getGameYamlTest", () => {
  it("returns the verdict and its summarised error", async () => {
    server.use(
      http.get(`${BASE}/games/game-hk/yaml-test/job.sig`, () =>
        HttpResponse.json({ data: { status: "failed", error: "Goal is not a valid choice" } }),
      ),
    );

    expect(await getGameYamlTest("game-hk", "job.sig")).toEqual({ kind: "result", status: "failed", error: "Goal is not a valid choice", world: null });
  });

  it("gives the size of the tested world of a passed test (story 56.1)", async () => {
    server.use(
      http.get(`${BASE}/games/game-hk/yaml-test/job.sig`, () =>
        HttpResponse.json({ data: { status: "passed", error: null, measures: [{ name: "Jean", game: "Hollow Knight", locations: 283, ownProgression: 196 }] } }),
      ),
    );

    expect(await getGameYamlTest("game-hk", "job.sig")).toEqual({ kind: "result", status: "passed", error: null, world: { locations: 283, ownProgression: 196 } });
  });

  it("reads the duration and the target for an event (story 56.5)", async () => {
    let query = "";
    server.use(
      http.get(`${BASE}/games/game-hk/yaml-test/job.sig`, ({ request }) => {
        query = new URL(request.url).search;
        return HttpResponse.json({
          data: {
            status: "passed",
            error: null,
            measures: [{ name: "Jean", game: "Hollow Knight", locations: 283, ownProgression: 196 }],
            estimate: { locations: 283, hours: 28.3, source: "admin", targetHours: 19.2, overTarget: true },
          },
        });
      }),
    );

    expect(await getGameYamlTest("game-hk", "job.sig", { eventId: "ev-1" })).toEqual({
      kind: "result",
      status: "passed",
      error: null,
      world: { locations: 283, ownProgression: 196, hours: 28.3, targetHours: 19.2, overTarget: true },
    });
    expect(query).toBe("?eventId=ev-1");
  });

  it("reads a 404 as an unknown or expired test", async () => {
    server.use(http.get(`${BASE}/games/game-hk/yaml-test/job.sig`, () => HttpResponse.json({}, { status: 404 })));

    expect(await getGameYamlTest("game-hk", "job.sig")).toEqual({ kind: "gone" });
  });

  it("rejects an unknown status", async () => {
    server.use(
      http.get(`${BASE}/games/game-hk/yaml-test/job.sig`, () => HttpResponse.json({ data: { status: "exploded", error: null } })),
    );

    expect(await getGameYamlTest("game-hk", "job.sig")).toEqual({ kind: "error" });
  });
});
