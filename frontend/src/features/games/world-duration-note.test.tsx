import { renderToStaticMarkup } from "react-dom/server";

import { WorldDurationNote } from "./game-yaml-test";

describe("story 56.5: the duration of a tested world", () => {
  it("compares it with the target and warns past it", () => {
    const html = renderToStaticMarkup(<WorldDurationNote world={{ locations: 283, ownProgression: 196, hours: 28.3, targetHours: 19.2, overTarget: true }} />);

    expect(html).toContain("Environ 28 h de jeu pour toi");
    expect(html).toContain("la cible est de 19 h");
    expect(html).toContain("risque de dépasser");
  });

  it("stays quiet under the target", () => {
    const html = renderToStaticMarkup(<WorldDurationNote world={{ locations: 50, ownProgression: 9, hours: 4.5, targetHours: 19.2, overTarget: false }} />);

    expect(html).toContain("Environ 4,5 h de jeu pour toi ; la cible est de 19 h.");
    expect(html).not.toContain("risque");
  });

  it("says when the pace is unknown, and nothing without any figure", () => {
    expect(renderToStaticMarkup(<WorldDurationNote world={{ locations: 50, ownProgression: 9, hours: null, targetHours: 6 }} />)).toContain(
      "Durée inconnue pour ce jeu ; la cible est de 6 h.",
    );
    expect(renderToStaticMarkup(<WorldDurationNote world={{ locations: 50, ownProgression: 9 }} />)).toBe("");
  });
});
