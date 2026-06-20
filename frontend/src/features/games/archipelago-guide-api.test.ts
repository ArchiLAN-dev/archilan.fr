import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { getArchipelagoGuide } from "./archipelago-guide-api";

const BASE = TEST_API_BASE_URL;

const step = { type: "client", title: "Installer le launcher", description: "", links: [] };

describe("getArchipelagoGuide", () => {
  it("returns the guide steps on success", async () => {
    server.use(http.get(`${BASE}/archipelago-guide`, () => HttpResponse.json({ data: { steps: [step] } })));
    const result = await getArchipelagoGuide();
    expect(result).toHaveLength(1);
    expect(result[0].type).toBe("client");
  });

  it("returns empty array when unset", async () => {
    server.use(http.get(`${BASE}/archipelago-guide`, () => HttpResponse.json({ data: { steps: [] } })));
    expect(await getArchipelagoGuide()).toHaveLength(0);
  });

  it("returns empty array on malformed step", async () => {
    server.use(
      http.get(`${BASE}/archipelago-guide`, () => HttpResponse.json({ data: { steps: [{ type: "bogus" }] } })),
    );
    expect(await getArchipelagoGuide()).toHaveLength(0);
  });

  it("returns empty array on network error", async () => {
    server.use(http.get(`${BASE}/archipelago-guide`, () => HttpResponse.error()));
    expect(await getArchipelagoGuide()).toHaveLength(0);
  });
});
