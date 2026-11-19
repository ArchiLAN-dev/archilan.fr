import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderToStaticMarkup } from "react-dom/server";

import { TrackerPackUpdates } from "./admin-tracker-pack-updates";
import type { AdminTrackerPack } from "./admin-tracker-packs-api";

const pack: AdminTrackerPack = {
  id: "p1",
  name: "Majora's Mask: Recompiled",
  author: null,
  packageUid: "MM_AP",
  gameName: null,
  sourceUrl: "https://github.com/o/r",
  licenseSpdx: "MIT",
  hasApVariant: true,
  versionId: "v2",
  packageVersion: "0.9.5.3",
  gitTag: "v0.9.5.3",
  sizeBytes: 1024,
  variants: [{ uid: "standard", displayName: "Maps and items", flags: ["ap"] }],
  minPopTrackerVersion: null,
  importedAt: "2026-11-16T10:00:00+00:00",
  enabled: true,
  isDefault: true,
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

function render(p: AdminTrackerPack): string {
  return renderToStaticMarkup(
    <QueryClientProvider client={new QueryClient()}>
      <TrackerPackUpdates gameId="g1" gameSlug="majoras-mask" pack={p} />
    </QueryClientProvider>,
  );
}

describe("TrackerPackUpdates (story 54.3)", () => {
  it("offers a check, and says when it never ran", () => {
    const html = render(pack);
    expect(html).toContain("Vérifier maintenant");
    expect(html).toContain("Jamais vérifié");
    expect(html).not.toContain("disponible");
    expect(html).not.toContain("Versions gardées");
  });

  it("shows the update found with its changelog and the button to apply it", () => {
    const html = render({
      ...pack,
      lastCheckedAt: "2026-11-17T03:40:00+00:00",
      availableUpdate: {
        packageVersion: "0.9.5.4",
        downloadUrl: "https://github.com/o/r/archive/refs/tags/v0.9.5.4.zip",
        sha256: null,
        changelog: ["- Fixed the Great Bay logic"],
        gitTag: "v0.9.5.4",
      },
    });
    expect(html).toContain("v0.9.5.4 disponible");
    expect(html).toContain("Mettre à jour");
    expect(html).toContain("Ce qui change");
    expect(html).toContain("- Fixed the Great Bay logic");
    expect(html).toContain("Dernière vérification : 17/11/2026 04:40");
  });

  it("names a failed check", () => {
    expect(render({ ...pack, lastCheckedAt: "2026-11-17T03:40:00+00:00", lastCheckError: "github_rate_limited" })).toContain(
      "échec (GitHub limitait les requêtes)",
    );
  });

  it("lists the kept versions with a way back to the older ones", () => {
    const html = render({
      ...pack,
      versions: [
        { id: "v2", packageVersion: "0.9.5.3", gitTag: "v0.9.5.3", sizeBytes: 2048, importedAt: "2026-11-16T10:00:00+00:00", rootPath: "", sha256: "abc", variants: [], current: true },
        { id: "v1", packageVersion: "0.9.5.2", gitTag: "v0.9.5.2", sizeBytes: 2048, importedAt: "2026-11-01T10:00:00+00:00", rootPath: "", sha256: "abc", variants: [], current: false },
      ],
    });
    expect(html).toContain("Versions gardées");
    expect(html).toContain("En service");
    // Story 54.13: a kept version is tried, then served.
    expect(html.match(/Servir cette version/g)).toHaveLength(1);
    expect(html).toContain('href="/jeux/majoras-mask/tracker?pack=p1&amp;version=v1&amp;essai=1"');
  });
});
