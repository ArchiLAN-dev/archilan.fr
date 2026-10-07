import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import {
  approveApworldCandidate,
  forceApworldCandidate,
  getApworldCandidateYamlTest,
  isApworldCandidate,
  retryApworldCandidate,
  startApworldCandidateYamlTest,
} from "./admin-games-api";

const BASE = TEST_API_BASE_URL;

const candidate = {
  id: "candidate-1",
  status: "rejected",
  apworldHash: "3bf11e98093174eb2439df10e13caac0836a0bbb3f9ba53a91b42800081f97d6",
  versionTag: "CrystalProject-v0.18.2",
  origin: "auto",
  submittedAt: "2026-09-26T04:10:00+02:00",
  decidedAt: "2026-09-26T04:20:00+02:00",
  rejectionReason: "Fill.FillError: Could not access required locations",
};

/** Story 38.6: the new apworld version waiting for, or refused by, its test. */
describe("apworld candidate", () => {
  it("recognizes a candidate payload", () => {
    expect(isApworldCandidate(candidate)).toBe(true);
    expect(isApworldCandidate({ ...candidate, versionTag: null, decidedAt: null, rejectionReason: null })).toBe(true);
    expect(isApworldCandidate({ ...candidate, status: 42 })).toBe(false);
    expect(isApworldCandidate(null)).toBe(false);
  });

  it("story 38.14: recognizes a candidate awaiting the admin's approval", () => {
    expect(isApworldCandidate({ ...candidate, status: "awaiting", heldForApproval: true })).toBe(true);
    expect(isApworldCandidate({ ...candidate, heldForApproval: "yes" })).toBe(false);
  });

  it.each([
    ["promote", forceApworldCandidate],
    ["retry", retryApworldCandidate],
    ["approve", approveApworldCandidate],
  ] as const)("posts %s for the game and reports success", async (action, call) => {
    let gameId = "";
    server.use(
      http.post(`${BASE}/admin/games/:gameId/apworld-candidate/${action}`, ({ params }) => {
        gameId = String(params.gameId);
        return HttpResponse.json({ data: { outcome: "applied" } });
      }),
    );

    expect(await call("game-1")).toEqual({ ok: true });
    expect(gameId).toBe("game-1");
  });

  it("surfaces the API message when the action is refused", async () => {
    server.use(
      http.post(`${BASE}/admin/games/:gameId/apworld-candidate/promote`, () =>
        HttpResponse.json(
          { error: { code: "runner_unavailable", message: "Le runner est indisponible, rien n'a été changé.", details: [] } },
          { status: 503 },
        ),
      ),
    );

    expect(await forceApworldCandidate("game-1")).toEqual({ ok: false, message: "Le runner est indisponible, rien n'a été changé." });
  });

  it("story 38.14: starts a YAML test on the candidate and returns its id", async () => {
    let body: unknown = null;
    server.use(
      http.post(`${BASE}/admin/games/:gameId/apworld-candidate/test-yaml`, async ({ request }) => {
        body = await request.json();
        return HttpResponse.json({ data: { jobId: "job-1" } }, { status: 202 });
      }),
    );

    expect(await startApworldCandidateYamlTest("game-1", "name: Jean\n")).toEqual({ ok: true, jobId: "job-1" });
    expect(body).toEqual({ yaml: "name: Jean\n" });
  });

  it("story 38.14: surfaces a refused YAML test", async () => {
    server.use(
      http.post(`${BASE}/admin/games/:gameId/apworld-candidate/test-yaml`, () =>
        HttpResponse.json({ error: { code: "validation_failed", message: "Colle un YAML (100 Ko au plus).", details: [] } }, { status: 422 }),
      ),
    );

    expect(await startApworldCandidateYamlTest("game-1", "")).toEqual({ ok: false, message: "Colle un YAML (100 Ko au plus)." });
  });

  it("story 38.14: reads a YAML test result, and an expired one", async () => {
    server.use(
      http.get(`${BASE}/admin/games/:gameId/apworld-candidate/test-yaml/:jobId`, ({ params }) =>
        params.jobId === "gone"
          ? HttpResponse.json({ error: { code: "not_found", message: "Test introuvable ou expiré : relance-le.", details: [] } }, { status: 404 })
          : HttpResponse.json({ data: { status: "failed", error: "AttributeError: architect" } }),
      ),
    );

    expect(await getApworldCandidateYamlTest("game-1", "job-1")).toEqual({ kind: "result", status: "failed", error: "AttributeError: architect" });
    expect(await getApworldCandidateYamlTest("game-1", "gone")).toEqual({ kind: "gone" });
  });
});
