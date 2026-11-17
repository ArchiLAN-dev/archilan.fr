import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
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
  enabled: false,
  isDefault: false,
  defaultVariantUid: null,
  licenseAcknowledged: false,
  availableUpdate: null,
  lastCheckedAt: null,
  lastCheckError: null,
  versions: [],
  rootPath: "",
  sha256: "abc",
  compatibility: [],
};

function render(node: ReactNode): string {
  return renderToStaticMarkup(<QueryClientProvider client={new QueryClient()}>{node}</QueryClientProvider>);
}

function list(packs: AdminTrackerPack[] | null | undefined, loading = false): string {
  return render(<TrackerPackList gameId="g1" gameSlug="majora" loading={loading} packs={packs} />);
}

describe("AdminGameTrackerPacks (story 54.1)", () => {
  it("offers to import a pack from a GitHub link", () => {
    const html = render(<AdminGameTrackerPacks gameId="g1" gameSlug="majora" />);
    expect(html).toContain("Trackers");
    expect(html).toContain("Lien GitHub du pack");
    // Nothing typed yet: the button waits for a link.
    expect(html).toMatch(/<button[^>]*disabled=""[^>]*type="submit"/);
  });
});

describe("TrackerPackList", () => {
  it("shows each pack with its version, variants, licence and source", () => {
    const html = list([pack]);
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
    const html = list([{ ...pack, hasApVariant: false, licenseSpdx: null }]);
    expect(html).toContain("Sans autotracking");
    expect(html).toContain("Non détectée, à vérifier avant de le proposer aux joueurs");
  });

  it("says when there is nothing, and when the list failed", () => {
    expect(list([])).toContain("Aucun pack importé pour ce jeu.");
    expect(list(null)).toContain("Impossible de charger les packs de ce jeu.");
    expect(list(undefined, true)).toContain("Chargement des packs…");
  });
});

describe("TrackerPackList sharing controls (story 54.2)", () => {
  it("offers to enable a licensed pack, without the licence box", () => {
    const html = list([pack]);
    expect(html).toContain("Proposé aux joueurs");
    expect(html).not.toContain("La diffusion de ce pack est autorisée");
    expect(html).not.toMatch(/<input[^>]*disabled=""[^>]*type="checkbox"/);
    expect(html).not.toContain("Par défaut");
    expect(html).not.toContain("Ouvrir ce pack par défaut");
  });

  it("locks the switch of a pack without licence until an admin vouches for it", () => {
    const locked = list([{ ...pack, licenseSpdx: null }]);
    expect(locked).toContain("La diffusion de ce pack est autorisée");
    expect(locked).toMatch(/<input[^>]*disabled=""[^>]*type="checkbox"/);

    const vouched = list([{ ...pack, licenseSpdx: null, licenseAcknowledged: true }]);
    expect(vouched).not.toMatch(/<input[^>]*disabled=""[^>]*type="checkbox"/);
  });

  it("marks the default pack and offers the star on the other enabled ones", () => {
    const html = list([
      { ...pack, enabled: true, isDefault: true },
      { ...pack, id: "p2", enabled: true },
    ]);
    expect(html).toContain("Par défaut");
    expect(html.match(/Ouvrir ce pack par défaut/g)).toHaveLength(1);
  });

  it("lists the variants to open first, the first one by default", () => {
    const html = list([{ ...pack, defaultVariantUid: "itemsonly" }]);
    expect(html).toContain("La première (Maps and items)");
    expect(html).toMatch(/<option value="itemsonly" selected="">Items only<\/option>/);
  });
});
