import { parsePatchFiles } from "./patch-files";
import { TEST_API_BASE_URL } from "../tests/constants";

/**
 * Story 16.16. L'API signe et renvoie un chemin ; le navigateur a besoin d'une URL absolue pour la
 * poser dans un href, et le joueur d'une adresse complète pour l'envoyer à quelqu'un.
 */
describe("parsePatchFiles", () => {
  test("résout le chemin signé en URL absolue sur l'origine de l'API", () => {
    const files = parsePatchFiles({
      data: { files: [{ name: "AP_1_P2_kafey.aplm", url: "/api/v1/public/patches/AP_1_P2_kafey.aplm?archive=k&_hash=h" }] },
    });

    expect(files).toHaveLength(1);
    expect(files[0].name).toBe("AP_1_P2_kafey.aplm");
    // Le chemin absolu remplace celui de la base : les deux ne s'empilent pas.
    expect(files[0].url).toBe(
      new URL("/api/v1/public/patches/AP_1_P2_kafey.aplm?archive=k&_hash=h", TEST_API_BASE_URL).toString(),
    );
    expect(files[0].url).not.toContain("/api/v1/api/v1");
  });

  test("un fichier sans lien public reste listé, à charge de l'appelant de retomber sur la route authentifiée", () => {
    expect(parsePatchFiles({ data: { files: [{ name: "legacy.apz2", url: null }] } })).toEqual([
      { name: "legacy.apz2", url: null },
    ]);
  });

  test("les lignes malformées sont écartées, jamais castées", () => {
    const files = parsePatchFiles({
      data: { files: [{ name: "ok.aplm", url: "/api/v1/public/patches/ok.aplm" }, { url: "orpheline" }, "chaine", null, { name: "" }] },
    });

    expect(files.map((f) => f.name)).toEqual(["ok.aplm"]);
  });

  test("une charge inattendue donne une liste vide, pas un panneau cassé", () => {
    expect(parsePatchFiles(null)).toEqual([]);
    expect(parsePatchFiles({})).toEqual([]);
    expect(parsePatchFiles({ data: {} })).toEqual([]);
    expect(parsePatchFiles({ data: { files: "pas un tableau" } })).toEqual([]);
  });
});
