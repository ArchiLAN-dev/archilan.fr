import { renderToStaticMarkup } from "react-dom/server";

import { ApworldPreflightWarning } from "./apworld-preflight-warning";

const WARNING =
  "Could not access required locations for accessibility check. Missing: [Discover: Evil Dragon, Discover: Negative Energy, Discover: Ultimate Dragonball]";

/** Story 38.12 : un test réussi avec avertissement (accessibilité non tenue, laissée passer comme le Launcher). */
describe("ApworldPreflightWarning", () => {
  test("dit combien d'emplacements sont inatteignables et lesquels", () => {
    const html = renderToStaticMarkup(<ApworldPreflightWarning warning={WARNING} />);

    expect(html).toContain("3 emplacements inatteignables");
    expect(html).toContain("Discover: Evil Dragon");
    expect(html).toContain("Discover: Ultimate Dragonball");
    expect(html).toContain("la partie reste gagnable");
    expect(html).toContain("auteur de l&#x27;apworld");
  });

  test("montre le texte brut d'un avertissement d'un autre type", () => {
    const html = renderToStaticMarkup(<ApworldPreflightWarning warning="Something else happened" />);

    expect(html).toContain("Something else happened");
  });

  test("ne montre rien sans avertissement", () => {
    expect(renderToStaticMarkup(<ApworldPreflightWarning warning="" />)).toBe("");
  });
});
