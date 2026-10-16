import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { rerunApworldPreflight, startApworldCandidateYamlTest } from "./admin-games-api";
import { passedWithFilesLabel, testFilesBlocker, testFilesOfGame } from "./admin-game-test-files";

const BASE = TEST_API_BASE_URL;
const rom = { setting: "DK3Settings.rom_file", file: "dkc3.sfc", extension: ".sfc", description: "DKC3 (US) ROM File", md5s: ["120abf304f0c40fe059f6a192ed4f947"], neededAtGeneration: true };

/** Story 44.6: the admin's test generations of a ROM game carry the ROM. */
describe("admin tests with a provided file", () => {
  it("asks only for the files a generation needs", () => {
    const declared = { ...rom, setting: "DK3Settings.other", neededAtGeneration: false };

    expect(testFilesOfGame([rom, declared, { nonsense: true }]).map((f) => f.setting)).toEqual(["DK3Settings.rom_file"]);
    expect(testFilesOfGame(null)).toEqual([]);
  });

  it("lets a test run without the file, never with a wrong or unchecked one", () => {
    expect(testFilesBlocker({})).toBeNull();
    expect(testFilesBlocker({ a: { kind: "accepted", file: new File(["x"], "a.sfc"), md5: "m" } })).toBeNull();
    expect(testFilesBlocker({ a: { kind: "checking", progress: 0.5 } })).toMatch(/vérif/i);
    expect(testFilesBlocker({ a: { kind: "wrong", md5: "m", expected: "DKC3" } })).not.toBeNull();
    expect(testFilesBlocker({ a: { kind: "tooLarge" } })).not.toBeNull();
  });

  it("names the file a pass was obtained with", () => {
    expect(passedWithFilesLabel({ status: "passed", withRequiredFiles: true }, [rom])).toBe("Test de génération réussi avec DKC3 (US) ROM File");
    expect(passedWithFilesLabel({ status: "passed", withRequiredFiles: false }, [rom])).toBeNull();
    expect(passedWithFilesLabel({ status: "failed", withRequiredFiles: true }, [rom])).toBeNull();
  });

  it("sends the file with the re-check, and nothing without one", async () => {
    const seen: { type: string | null; settings: string[] }[] = [];
    server.use(
      http.post(`${BASE}/admin/games/:gameId/apworld-preflight`, async ({ request }) => {
        const type = request.headers.get("content-type");
        const settings = type?.startsWith("multipart/") ? [...(await request.formData()).keys()] : [];
        seen.push({ type, settings });
        return HttpResponse.json({ data: { status: "pending" } }, { status: 202 });
      }),
    );

    expect(await rerunApworldPreflight("game-1", { "DK3Settings.rom_file": new File(["rom"], "dkc3.sfc") })).toEqual({ ok: true });
    expect(await rerunApworldPreflight("game-1")).toEqual({ ok: true });
    expect(seen[0].settings).toEqual(["requiredFiles[DK3Settings.rom_file]"]);
    expect(seen[1].settings).toEqual([]);
  });

  it("surfaces a refused file", async () => {
    server.use(
      http.post(`${BASE}/admin/games/:gameId/apworld-preflight`, () =>
        HttpResponse.json(
          { error: { code: "required_file_refused", message: "Le fichier n'a pas pu être transmis. Réessaie dans un instant.", details: {} } },
          { status: 422 },
        ),
      ),
    );

    expect(await rerunApworldPreflight("game-1", { "DK3Settings.rom_file": new File(["rom"], "dkc3.sfc") })).toEqual({
      ok: false,
      message: "Le fichier n'a pas pu être transmis. Réessaie dans un instant.",
    });
  });

  it("sends the YAML beside the file in one multipart request", async () => {
    let fields: Record<string, string> = {};
    server.use(
      http.post(`${BASE}/admin/games/:gameId/apworld-candidate/test-yaml`, async ({ request }) => {
        const form = await request.formData();
        // A multipart text field travels with CRLF line ends (FormData's own rule); a YAML reads the same either way.
        fields = Object.fromEntries([...form.entries()].map(([k, v]) => [k, typeof v === "string" ? v.replace(/\r\n/g, "\n") : `file:${v.name}`]));
        return HttpResponse.json({ data: { jobId: "job-1" } }, { status: 202 });
      }),
    );

    expect(await startApworldCandidateYamlTest("game-1", "name: Jean\n", { "DK3Settings.rom_file": new File(["rom"], "dkc3.sfc") })).toEqual({ ok: true, jobId: "job-1" });
    expect(fields).toEqual({ yaml: "name: Jean\n", "requiredFiles[DK3Settings.rom_file]": "file:dkc3.sfc" });
  });
});
