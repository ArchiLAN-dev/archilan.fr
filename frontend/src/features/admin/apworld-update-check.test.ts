import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { fetchApworldUpdateCheck, startApworldUpdateCheck } from "./admin-catalogue-sync-api";
import { describeUpdateCheck, isApworldUpdateCheckRun, isUpdateCheckActive, type ApworldUpdateCheckRun } from "./apworld-update-check";

const URL = `${TEST_API_BASE_URL}/admin/catalog-sync/check-updates`;

function run(overrides: Partial<ApworldUpdateCheckRun> = {}): ApworldUpdateCheckRun {
  return {
    id: "run-1",
    status: "queued",
    trigger: "button",
    queuedAt: "2026-10-19T10:00:00+02:00",
    startedAt: null,
    finishedAt: null,
    total: null,
    checked: 0,
    failed: 0,
    rateLimitHit: false,
    updatesAvailable: 0,
    error: null,
    ...overrides,
  };
}

describe("état d'une vérification des mises à jour (story 38.18)", () => {
  it("lancée : en arrière-plan, le bouton reste désactivé", () => {
    expect(isUpdateCheckActive(run())).toBe(true);
    expect(describeUpdateCheck(run())).toEqual({ tone: "info", text: "Vérification lancée en arrière-plan : elle démarre dès que le worker est libre." });
  });

  it("en cours : l'avancement sur le total", () => {
    const running = run({ status: "running", total: 556, checked: 118, failed: 2 });

    expect(isUpdateCheckActive(running)).toBe(true);
    expect(describeUpdateCheck(running).text).toBe("Vérification lancée depuis cette page en cours : 120 / 556 jeux.");
  });

  it("terminée : le bilan, et la limite GitHub signalée", () => {
    const done = run({ status: "done", trigger: "nightly", finishedAt: "2026-10-19T04:12:00+02:00", checked: 300, failed: 1, updatesAvailable: 4, rateLimitHit: true });

    expect(isUpdateCheckActive(done)).toBe(false);
    const { tone, text } = describeUpdateCheck(done);
    expect(tone).toBe("warning");
    expect(text).toContain("Dernière vérification de nuit");
    expect(text).toContain("300 jeux vérifiés, 1 en échec, 4 mises à jour disponibles.");
    expect(text).toContain("Limite GitHub atteinte");
  });

  it("terminée sans souci : un succès", () => {
    expect(describeUpdateCheck(run({ status: "done", finishedAt: "2026-10-19T10:05:00+02:00", checked: 1 })).tone).toBe("success");
  });

  it("échec : l'erreur enregistrée", () => {
    const failed = run({ status: "failed", finishedAt: "2026-10-19T10:01:00+02:00", error: "Une autre vérification tournait déjà." });

    expect(isUpdateCheckActive(failed)).toBe(false);
    expect(describeUpdateCheck(failed)).toMatchObject({ tone: "error" });
    expect(describeUpdateCheck(failed).text).toContain("Une autre vérification tournait déjà.");
  });

  it("refuse une forme inattendue", () => {
    expect(isApworldUpdateCheckRun(run())).toBe(true);
    expect(isApworldUpdateCheckRun({ ...run(), status: "paused" })).toBe(false);
    expect(isApworldUpdateCheckRun({ ...run(), total: "12" })).toBe(false);
  });
});

describe("client de la vérification des mises à jour", () => {
  it("met la vérification en file", async () => {
    server.use(http.post(URL, () => HttpResponse.json({ data: run() }, { status: 202 })));
    expect(await startApworldUpdateCheck()).toBe("queued");
  });

  it("signale une vérification déjà en cours", async () => {
    server.use(http.post(URL, () => HttpResponse.json({ error: { code: "already_running" } }, { status: 409 })));
    expect(await startApworldUpdateCheck()).toBe("already_running");
  });

  it("lit la dernière passe, ou son absence", async () => {
    server.use(http.get(URL, () => HttpResponse.json({ data: run({ status: "running" }) })));
    expect(await fetchApworldUpdateCheck()).toEqual({ kind: "ok", run: run({ status: "running" }) });

    server.use(http.get(URL, () => HttpResponse.json({ data: null })));
    expect(await fetchApworldUpdateCheck()).toEqual({ kind: "ok", run: null });

    server.use(http.get(URL, () => HttpResponse.error()));
    expect(await fetchApworldUpdateCheck()).toEqual({ kind: "error" });
  });
});
