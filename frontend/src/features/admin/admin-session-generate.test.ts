import { http, HttpResponse } from "msw";
import { server } from "@/tests/setup";
import { TEST_API_BASE_URL } from "@/tests/constants";
import { fetchAdminSessionDetail, generateSession } from "./admin-sessions-api";

const SESSION = {
  id: "s1",
  eventId: "e1",
  status: "generating",
  host: null,
  port: null,
  password: null,
  createdAt: "2026-10-16T10:00:00Z",
  startedAt: null,
  stoppedAt: null,
};

const ROM = {
  setting: "DK3Settings.rom_file",
  file: "dkc3.sfc",
  extension: ".sfc",
  description: "DKC3 (US) ROM File",
  md5s: ["120abf304f0c40fe059f6a192ed4f947"],
  neededAtGeneration: true,
  games: ["Donkey Kong Country 3"],
};

describe("admin session generation with its files (story 44.5)", () => {
  it("reads the files the session's games need", async () => {
    server.use(
      http.get(`${TEST_API_BASE_URL}/admin/sessions/s1`, () =>
        HttpResponse.json({ data: { session: { ...SESSION, status: "ready" }, slots: [], generationFiles: [ROM, { broken: true }] } }),
      ),
    );

    const result = await fetchAdminSessionDetail("s1");

    expect(result.kind).toBe("ready");
    expect(result.kind === "ready" ? result.generationFiles : null).toEqual([ROM]);
  });

  it("sends the files under their setting and returns the session", async () => {
    let sent: FormDataEntryValue | null = null;
    server.use(
      http.post(`${TEST_API_BASE_URL}/admin/sessions/s1/generate`, async ({ request }) => {
        sent = (await request.formData()).get("requiredFiles[DK3Settings.rom_file]");
        return HttpResponse.json({ data: SESSION });
      }),
    );

    const result = await generateSession("s1", { "DK3Settings.rom_file": new File(["rom"], "dkc3.sfc") });

    expect(result).toEqual({ ok: true, session: SESSION });
    expect(sent).toBeInstanceOf(File);
  });

  it("says why the generation could not start instead of dropping it", async () => {
    server.use(
      http.post(`${TEST_API_BASE_URL}/admin/sessions/s1/generate`, () =>
        HttpResponse.json({ error: { code: "runner_unavailable", message: "Le runner est indisponible.", details: {} } }, { status: 503 }),
      ),
    );
    expect(await generateSession("s1")).toEqual({ ok: false, message: "Le runner est indisponible." });

    server.use(
      http.post(`${TEST_API_BASE_URL}/admin/sessions/s1/generate`, () =>
        HttpResponse.json(
          { error: { code: "validation_failed", message: "Fichier de génération invalide.", details: { requiredFiles: ["2 fichiers au plus."] } } },
          { status: 422 },
        ),
      ),
    );
    expect(await generateSession("s1")).toEqual({ ok: false, message: "2 fichiers au plus." });
  });
});
