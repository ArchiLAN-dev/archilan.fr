import { renderToStaticMarkup } from "react-dom/server";

import { GameTrackerCredits } from "./game-tracker-credits";
import type { GameTrackerPack } from "./tracker-packs-api";

const pack: GameTrackerPack = {
  id: "p1",
  name: "Majora's Mask: Recompiled",
  author: "Seto10987, G4M3R L1F3",
  licenseSpdx: "MIT",
  sourceUrl: "https://github.com/G4M3RL1F3/Majoras-Mask-AP-PopTracker-Pack",
  isDefault: true,
  hasApVariant: true,
  defaultVariantUid: null,
  version: { id: "v1", packageVersion: "0.9.5.3", rootPath: "", sizeBytes: 1000, sha256: "abc", variants: [] },
};

describe("GameTrackerCredits (story 54.2)", () => {
  it("renders nothing when the game has no tracker", () => {
    expect(renderToStaticMarkup(<GameTrackerCredits packs={[]} />)).toBe("");
  });

  it("credits the default pack: name, author, licence and source", () => {
    const html = renderToStaticMarkup(<GameTrackerCredits packs={[pack]} />);
    expect(html).toContain("Tracker disponible");
    expect(html).toContain("Majora&#x27;s Mask: Recompiled");
    expect(html).toContain("v0.9.5.3");
    expect(html).toContain("par Seto10987, G4M3R L1F3");
    expect(html).toContain("licence MIT");
    expect(html).toContain('href="https://github.com/G4M3RL1F3/Majoras-Mask-AP-PopTracker-Pack"');
    expect(html).not.toContain("Autres packs proposés");
  });

  it("names the other packs offered after the default one", () => {
    const html = renderToStaticMarkup(
      <GameTrackerCredits packs={[pack, { ...pack, id: "p2", name: "MM N64", author: null, licenseSpdx: null, isDefault: false }]} />,
    );
    expect(html).toContain("Autres packs proposés");
    expect(html).toContain("MM N64");
    expect(html).toContain("Auteur non précisé");
  });
});
