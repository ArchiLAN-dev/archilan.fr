import { renderToStaticMarkup } from "react-dom/server";

import { CandidateYamlTest, YamlTestOutcome } from "./candidate-yaml-test";

/** Story 38.14: generate a pasted YAML against the candidate version, before putting it online. */
describe("CandidateYamlTest", () => {
  test("offers a YAML field and a test button, and says nothing goes online", () => {
    const html = renderToStaticMarkup(<CandidateYamlTest gameId="game-1" onClose={() => undefined} />);

    expect(html).toContain("<textarea");
    expect(html).toContain("rien n&#x27;est mis en ligne");
    expect(html).not.toContain("Fichiers à fournir");
  });

  // Story 44.6: a ROM game's test asks for the ROM, and says it fails without it.
  test("asks for the file a ROM game's generation needs", () => {
    const rom = { setting: "DK3Settings.rom_file", file: "dkc3.sfc", extension: ".sfc", description: "DKC3 (US) ROM File", md5s: [], neededAtGeneration: true };
    const html = renderToStaticMarkup(<CandidateYamlTest gameId="game-1" onClose={() => undefined} requiredFiles={[rom]} />);

    expect(html).toContain("Fichiers à fournir");
    expect(html).toContain("DKC3 (US) ROM File");
    expect(html).toContain("Sans ce fichier, le test échouera.");
  });
});

describe("YamlTestOutcome", () => {
  test("a pass says the version generates with this YAML", () => {
    expect(renderToStaticMarkup(<YamlTestOutcome state={{ kind: "done", status: "passed", error: null }} />)).toContain("Génération réussie");
  });

  test("a failure shows the error", () => {
    const html = renderToStaticMarkup(<YamlTestOutcome state={{ kind: "done", status: "failed", error: "AttributeError: architect" }} />);

    expect(html).toContain("Génération échouée");
    expect(html).toContain("AttributeError: architect");
  });

  test("a running test and an expired one say so", () => {
    expect(renderToStaticMarkup(<YamlTestOutcome state={{ kind: "running", jobId: "job-1" }} />)).toContain("Génération en cours");
    expect(renderToStaticMarkup(<YamlTestOutcome state={{ kind: "error", message: "Test expiré" }} />)).toContain("Test expiré");
  });
});
