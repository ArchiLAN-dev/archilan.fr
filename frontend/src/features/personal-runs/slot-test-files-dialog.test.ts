import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { requestSlotPreflight } from "./personal-runs-api";
import { slotTestBlocker, slotTestFiles } from "./slot-test-files-dialog";

const BASE = TEST_API_BASE_URL;
const rom = { setting: "DK3Settings.rom_file", file: "dkc3.sfc", extension: ".sfc", description: "DKC3 (US) ROM File", md5s: [], neededAtGeneration: true };

/** Story 44.7: « Tester ma config » for a game that reads a ROM. */
describe("slot test with a required file", () => {
  it("asks only for the files a generation proved necessary", () => {
    expect(slotTestFiles([rom, { ...rom, setting: "DK3Settings.other", neededAtGeneration: false }]).map((f) => f.setting)).toEqual([
      "DK3Settings.rom_file",
    ]);
    expect(slotTestFiles(undefined)).toEqual([]);
  });

  it("waits for the file before the test", () => {
    const needed = slotTestFiles([rom]);

    expect(slotTestBlocker(needed, {})).toBe("Choisis le fichier « DKC3 (US) ROM File » pour lancer le test.");
    expect(slotTestBlocker(needed, { "DK3Settings.rom_file": { kind: "checking", progress: 0.2 } })).toMatch(/vérification/i);
    expect(slotTestBlocker(needed, { "DK3Settings.rom_file": { kind: "accepted", file: new File(["x"], "dkc3.sfc"), md5: null } })).toBeNull();
  });

  it("sends the ROM with the test, nothing without it, and says why a refusal happened", async () => {
    const seen: string[][] = [];
    server.use(
      http.post(`${BASE}/runs/:runId/participants/me/slots/:slotId/preflight`, async ({ request }) => {
        const type = request.headers.get("content-type") ?? "";
        seen.push(type.startsWith("multipart/") ? [...(await request.formData()).keys()] : []);
        return seen.length < 3
          ? HttpResponse.json({ data: { status: "pending" } }, { status: 202 })
          : HttpResponse.json({ error: { code: "required_file_refused", message: "Le fichier n'a pas pu être transmis. Réessaie dans un instant.", details: {} } }, { status: 422 });
      }),
    );

    expect(await requestSlotPreflight("run-1", "slot-1", { "DK3Settings.rom_file": new File(["rom"], "dkc3.sfc") })).toEqual({ ok: true });
    expect(await requestSlotPreflight("run-1", "slot-1")).toEqual({ ok: true });
    expect(await requestSlotPreflight("run-1", "slot-1", { "DK3Settings.rom_file": new File(["rom"], "dkc3.sfc") })).toEqual({
      ok: false,
      message: "Le fichier n'a pas pu être transmis. Réessaie dans un instant.",
    });
    expect(seen[0]).toEqual(["requiredFiles[DK3Settings.rom_file]"]);
    expect(seen[1]).toEqual([]);
  });
});
