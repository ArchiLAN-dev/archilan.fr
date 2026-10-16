import { http, HttpResponse } from "msw";
import { renderToStaticMarkup } from "react-dom/server";
import { server } from "@/tests/setup";
import { TEST_API_BASE_URL } from "@/tests/constants";
import { AdminGameRequiredFiles, requiredFileStatus } from "./admin-game-required-files";
import { clearRequiredFileNeed } from "./admin-games-api";

const FILE = {
  setting: "DK3Settings.rom_file",
  file: "dkc3.sfc",
  extension: ".sfc",
  description: "DKC3 (US) ROM File",
  md5s: ["120abf304f0c40fe059f6a192ed4f947"],
  neededAtGeneration: true,
};

describe("admin required files (story 44.3)", () => {
  it("tells a mandatory file from a declared one", () => {
    expect(requiredFileStatus(FILE).label).toBe("Exigé à la génération");
    expect(requiredFileStatus({ ...FILE, neededAtGeneration: false }).label).toBe("Déclaré, optionnel");
  });

  it("lists the files, and offers to clear a mandatory one", () => {
    const html = renderToStaticMarkup(<AdminGameRequiredFiles gameId="g1" onUpdate={() => undefined} requiredFiles={[FILE]} />);

    expect(html).toContain("DKC3 (US) ROM File");
    expect(html).toContain("120abf304f0c40fe059f6a192ed4f947");
    expect(html).toContain("Ne plus l&#x27;exiger");
  });

  it("renders nothing for a game without files", () => {
    expect(renderToStaticMarkup(<AdminGameRequiredFiles gameId="g1" onUpdate={() => undefined} requiredFiles={[]} />)).toBe("");
  });

  it("clears the need through the API", async () => {
    let called = "";
    server.use(
      http.delete(`${TEST_API_BASE_URL}/admin/games/g1/required-files/:setting/needed`, ({ params }) => {
        called = String(params.setting);
        return HttpResponse.json({ data: { id: "g1", name: "Donkey Kong Country 3", requiredFiles: [{ ...FILE, neededAtGeneration: false }] } });
      }),
    );

    const updated = await clearRequiredFileNeed("g1", "DK3Settings.rom_file");

    expect(called).toBe("DK3Settings.rom_file");
    expect(updated?.id).toBe("g1");
  });

  it("returns null when the request fails", async () => {
    server.use(http.delete(`${TEST_API_BASE_URL}/admin/games/g1/required-files/:setting/needed`, () => HttpResponse.json({}, { status: 422 })));

    expect(await clearRequiredFileNeed("g1", "DK3Settings.rom_file")).toBeNull();
  });
});
