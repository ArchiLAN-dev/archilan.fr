import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { getArchipelagoClient } from "./archipelago-client-api";

const BASE = TEST_API_BASE_URL;

describe("getArchipelagoClient", () => {
  it("returns the client info on success", async () => {
    server.use(
      http.get(`${BASE}/archipelago-client`, () =>
        HttpResponse.json({ data: { version: "0.5.1", downloadUrl: "https://example.org" } }),
      ),
    );
    const result = await getArchipelagoClient();
    expect(result?.version).toBe("0.5.1");
  });

  it("returns null when unset (data null)", async () => {
    server.use(http.get(`${BASE}/archipelago-client`, () => HttpResponse.json({ data: null })));
    expect(await getArchipelagoClient()).toBeNull();
  });

  it("returns null on malformed payload", async () => {
    server.use(http.get(`${BASE}/archipelago-client`, () => HttpResponse.json({ data: { version: 1 } })));
    expect(await getArchipelagoClient()).toBeNull();
  });

  it("returns null on network error", async () => {
    server.use(http.get(`${BASE}/archipelago-client`, () => HttpResponse.error()));
    expect(await getArchipelagoClient()).toBeNull();
  });
});
