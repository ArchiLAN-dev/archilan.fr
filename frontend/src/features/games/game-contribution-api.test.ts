import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { getMyContributions, submitContribution } from "./game-contribution-api";

const BASE = TEST_API_BASE_URL;

describe("submitContribution", () => {
  it("returns true on 201", async () => {
    server.use(http.post(`${BASE}/game-contributions`, () => HttpResponse.json({ data: { id: "c1" } }, { status: 201 })));
    expect(await submitContribution({ gameSlug: "alttp", steps: [] })).toBe(true);
  });

  it("returns false on 422", async () => {
    server.use(http.post(`${BASE}/game-contributions`, () => new HttpResponse(null, { status: 422 })));
    expect(await submitContribution({ gameSlug: "alttp", steps: [] })).toBe(false);
  });
});

describe("getMyContributions", () => {
  it("returns the list on success", async () => {
    server.use(
      http.get(`${BASE}/game-contributions/me`, () =>
        HttpResponse.json({ data: [{ id: "c1", status: "pending", target: "ALTTP", stepCount: 2, createdAt: "2026-06-19" }] }),
      ),
    );
    const result = await getMyContributions();
    expect(result).toHaveLength(1);
    expect(result[0].target).toBe("ALTTP");
  });

  it("returns empty on malformed payload", async () => {
    server.use(http.get(`${BASE}/game-contributions/me`, () => HttpResponse.json({ data: [{ id: "c1" }] })));
    expect(await getMyContributions()).toHaveLength(0);
  });
});
