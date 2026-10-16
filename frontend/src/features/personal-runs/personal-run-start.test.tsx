import { http, HttpResponse } from "msw";
import { renderToStaticMarkup } from "react-dom/server";
import { server } from "@/tests/setup";
import { TEST_API_BASE_URL } from "@/tests/constants";
import { RequiredFilesPanel } from "@/features/games/required-files-panel";
import type { GenerationFile } from "@/features/games/required-files";
import { startRun } from "./personal-runs-api";

const ROM: GenerationFile = {
  setting: "DK3Settings.rom_file",
  file: "dkc3.sfc",
  extension: ".sfc",
  description: "DKC3 (US) ROM File",
  md5s: ["120abf304f0c40fe059f6a192ed4f947"],
  neededAtGeneration: true,
  games: ["Donkey Kong Country 3"],
};

describe("starting a run with its files (story 44.5)", () => {
  it("starts without a body when no file is needed", async () => {
    let contentType: string | null = "unset";
    server.use(
      http.post(`${TEST_API_BASE_URL}/runs/run-1/start`, ({ request }) => {
        contentType = request.headers.get("content-type");
        return HttpResponse.json({ data: { runId: "run-1", status: "starting" } }, { status: 202 });
      }),
    );

    expect(await startRun("run-1")).toEqual({ ok: true });
    expect(contentType).toBeNull();
  });

  it("sends each file under its setting", async () => {
    let sent: FormDataEntryValue | null = null;
    server.use(
      http.post(`${TEST_API_BASE_URL}/runs/run-1/start`, async ({ request }) => {
        sent = (await request.formData()).get("requiredFiles[DK3Settings.rom_file]");
        return HttpResponse.json({ data: { runId: "run-1", status: "starting" } }, { status: 202 });
      }),
    );

    expect(await startRun("run-1", { "DK3Settings.rom_file": new File(["rom"], "dkc3.sfc") })).toEqual({ ok: true });
    expect(sent).toBeInstanceOf(File);
  });

  it("returns the field error of a refused file, else the API message", async () => {
    server.use(
      http.post(`${TEST_API_BASE_URL}/runs/run-1/start`, () =>
        HttpResponse.json(
          { error: { code: "validation_failed", message: "Fichier de génération invalide.", details: { requiredFiles: ["Fichier trop lourd pour DK3Settings.rom_file (64 Mo au plus)."] } } },
          { status: 422 },
        ),
      ),
    );
    expect(await startRun("run-1")).toEqual({ ok: false, message: "Fichier trop lourd pour DK3Settings.rom_file (64 Mo au plus)." });

    server.use(
      http.post(`${TEST_API_BASE_URL}/runs/run-1/start`, () =>
        HttpResponse.json({ error: { code: "required_file_refused", message: "Le fichier n'a pas pu être transmis pour la génération.", details: {} } }, { status: 422 }),
      ),
    );
    expect(await startRun("run-1")).toEqual({ ok: false, message: "Le fichier n'a pas pu être transmis pour la génération." });
  });

  it("asks for the needed files only, with the games concerned", () => {
    const html = renderToStaticMarkup(
      <RequiredFilesPanel needed={[ROM]} onChoose={() => undefined} states={{ [ROM.setting]: { kind: "wrong", md5: "abc", expected: "DKC3 (US) ROM File" } }} />,
    );

    expect(html).toContain("Fichiers à fournir");
    expect(html).toContain("Donkey Kong Country 3");
    expect(html).toContain('accept=".sfc"');
    expect(html).toContain("Ce n&#x27;est pas le bon fichier");
    expect(renderToStaticMarkup(<RequiredFilesPanel needed={[]} onChoose={() => undefined} states={{}} />)).toBe("");
  });
});
