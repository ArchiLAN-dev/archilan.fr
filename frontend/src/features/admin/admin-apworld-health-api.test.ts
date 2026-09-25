import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import {
  acknowledgeApworldIncident,
  fetchApworldIncidents,
  fetchApworldIncidentSummary,
  ignoreApworldIncident,
  resolveApworldIncident,
} from "./admin-apworld-health-api";

const BASE = TEST_API_BASE_URL;

const incident = {
  id: "incident-1",
  gameId: "game-1",
  gameName: "Crystal Project",
  apworldHash: "6d1ef721c1b08488d3f5a790984fbf5e6a956a1a7b2c3e66d280d1b1d744e136",
  type: "preflight_failed",
  status: "acknowledged",
  summary: "Fill.FillError: Could not access required locations",
  error: "Traceback...\nFill.FillError: Could not access required locations",
  openedAt: "2026-09-20T10:00:00+00:00",
  lastSeenAt: "2026-09-24T10:00:00+00:00",
  occurrences: 3,
  acknowledgedBy: { id: "admin-1", displayName: "Jean" },
  acknowledgedAt: "2026-09-21T09:00:00+00:00",
  closedAt: null,
  closedBy: null,
  closedAutomatically: false,
};

describe("fetchApworldIncidents", () => {
  it("asks for the requested scope and parses the incidents", async () => {
    let requestUrl = "";
    server.use(
      http.get(`${BASE}/admin/apworld-incidents`, ({ request }) => {
        requestUrl = request.url;
        return HttpResponse.json({ data: [incident], meta: { status: "active", count: 1 } });
      }),
    );

    const result = await fetchApworldIncidents("active");

    expect(new URL(requestUrl).searchParams.get("status")).toBe("active");
    expect(new URL(requestUrl).searchParams.has("gameId")).toBe(false);
    expect(result).toEqual([incident]);
  });

  it("narrows to one game when asked", async () => {
    let requestUrl = "";
    server.use(
      http.get(`${BASE}/admin/apworld-incidents`, ({ request }) => {
        requestUrl = request.url;
        return HttpResponse.json({ data: [], meta: { status: "active", count: 0 } });
      }),
    );

    await fetchApworldIncidents("active", "game-1");

    expect(new URL(requestUrl).searchParams.get("gameId")).toBe("game-1");
  });

  it("returns null on a malformed incident rather than half a list", async () => {
    server.use(
      http.get(`${BASE}/admin/apworld-incidents`, () =>
        HttpResponse.json({ data: [{ ...incident, occurrences: "three" }], meta: {} }),
      ),
    );

    expect(await fetchApworldIncidents("active")).toBeNull();
  });

  it("returns null when the API refuses", async () => {
    server.use(http.get(`${BASE}/admin/apworld-incidents`, () => HttpResponse.json({}, { status: 403 })));

    expect(await fetchApworldIncidents("active")).toBeNull();
  });
});

describe("fetchApworldIncidentSummary", () => {
  it("parses the two counts", async () => {
    server.use(
      http.get(`${BASE}/admin/apworld-incidents/summary`, () =>
        HttpResponse.json({ data: { active: 2, unacknowledged: 1 } }),
      ),
    );

    expect(await fetchApworldIncidentSummary()).toEqual({ active: 2, unacknowledged: 1 });
  });

  it("returns null when the API is unreachable", async () => {
    server.use(http.get(`${BASE}/admin/apworld-incidents/summary`, () => HttpResponse.error()));

    expect(await fetchApworldIncidentSummary()).toBeNull();
  });
});

describe("incident actions", () => {
  it.each([
    ["acknowledge", acknowledgeApworldIncident],
    ["resolve", resolveApworldIncident],
    ["ignore", ignoreApworldIncident],
  ] as const)("posts %s and reports success", async (action, call) => {
    let called = "";
    server.use(
      http.post(`${BASE}/admin/apworld-incidents/:id/${action}`, ({ params }) => {
        called = String(params.id);
        return HttpResponse.json({ data: { outcome: "applied" } });
      }),
    );

    expect(await call("incident-1")).toEqual({ ok: true });
    expect(called).toBe("incident-1");
  });

  it("surfaces the API message when the incident is already closed", async () => {
    server.use(
      http.post(`${BASE}/admin/apworld-incidents/:id/acknowledge`, () =>
        HttpResponse.json(
          { error: { code: "incident_closed", message: "Cet incident est déjà clos.", details: [] } },
          { status: 409 },
        ),
      ),
    );

    expect(await acknowledgeApworldIncident("incident-1")).toEqual({ ok: false, message: "Cet incident est déjà clos." });
  });
});
