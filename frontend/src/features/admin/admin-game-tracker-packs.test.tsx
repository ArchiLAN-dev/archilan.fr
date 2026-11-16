import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderToStaticMarkup } from "react-dom/server";

import { AdminGameTrackerPacks, TrackerPackList } from "./admin-game-tracker-packs";
import type { AdminTrackerPack } from "./admin-tracker-packs-api";

const pack: AdminTrackerPack = {
  id: "p1",
  name: "Majora's Mask: Recompiled",
  author: "Seto10987",
  packageUid: "MM_AP",
  gameName: null,
  sourceUrl: "https://github.com/G4M3RL1F3/Majoras-Mask-AP-PopTracker-Pack",
  licenseSpdx: "MIT",
  hasApVariant: true,
  versionId: "v1",
  packageVersion: "0.9.5.3",
  gitTag: "v0.9.5.3",
  sizeBytes: 300 * 1024,
  variants: [
    { uid: "standard", displayName: "Maps and items", flags: ["ap"] },
    { uid: "itemsonly", displayName: "Items only", flags: ["ap"] },
  ],
  minPopTrackerVersion: "0.32.0",
  importedAt: "2026-11-16T10:00:00+00:00",
};

describe("AdminGameTrackerPacks (story 54.1)", () => {
  it("offers to import a pack from a GitHub link", () => {
    const html = renderToStaticMarkup(
      <QueryClientProvider client={new QueryClient()}>
        <AdminGameTrackerPacks gameId="g1" />
      </QueryClientProvider>,
    );
    expect(html).toContain("Trackers");
    expect(html).toContain("Lien GitHub du pack");
    // Nothing typed yet: the button waits for a link.
    expect(html).toMatch(/<button[^>]*disabled=""[^>]*type="submit"/);
  });
});

describe("TrackerPackList", () => {
  it("shows each pack with its version, variants, licence and source", () => {
    const html = renderToStaticMarkup(<TrackerPackList loading={false} packs={[pack]} />);
    expect(html).toContain("Majora&#x27;s Mask: Recompiled");
    expect(html).toContain("v0.9.5.3");
    expect(html).toContain("Archipelago");
    expect(html).toContain("Maps and items, Items only");
    expect(html).toContain("MIT");
    expect(html).toContain("300 Ko");
    expect(html).toContain("Source (v0.9.5.3)");
    expect(html).toContain('href="https://github.com/G4M3RL1F3/Majoras-Mask-AP-PopTracker-Pack"');
  });

  it("flags a pack without autotracking or licence", () => {
    const html = renderToStaticMarkup(<TrackerPackList loading={false} packs={[{ ...pack, hasApVariant: false, licenseSpdx: null }]} />);
    expect(html).toContain("Sans autotracking");
    expect(html).toContain("Non détectée, à vérifier avant de le proposer aux joueurs");
  });

  it("says when there is nothing, and when the list failed", () => {
    expect(renderToStaticMarkup(<TrackerPackList loading={false} packs={[]} />)).toContain("Aucun pack importé pour ce jeu.");
    expect(renderToStaticMarkup(<TrackerPackList loading={false} packs={null} />)).toContain("Impossible de charger les packs de ce jeu.");
    expect(renderToStaticMarkup(<TrackerPackList loading packs={undefined} />)).toContain("Chargement des packs…");
  });
});
