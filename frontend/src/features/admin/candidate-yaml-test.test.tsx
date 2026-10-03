import { renderToStaticMarkup } from "react-dom/server";

import { CandidateYamlTest, YamlTestOutcome } from "./candidate-yaml-test";

/** Story 38.14: generate a pasted YAML against the candidate version, before putting it online. */
describe("CandidateYamlTest", () => {
  test("offers a YAML field and a test button, and says nothing goes online", () => {
    const html = renderToStaticMarkup(<CandidateYamlTest gameId="game-1" />);

    expect(html).toContain("Tester avec un YAML");
    expect(html).toContain("<textarea");
    expect(html).toContain("rien n&#x27;est mis en ligne");
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
