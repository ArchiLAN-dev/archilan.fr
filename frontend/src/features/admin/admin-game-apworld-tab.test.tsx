import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import { ApworldTab, preflightVerdictLabel } from "./admin-game-apworld-tab";
import type { AdminGame, ApworldPreflight } from "./admin-games-api";

const rom = { setting: "DK3Settings.rom_file", file: "dkc3.sfc", extension: ".sfc", description: "DKC3 (US) ROM File", md5s: [], neededAtGeneration: true };
const failed: ApworldPreflight = { status: "failed", error: "FileNotFoundError: dkc3.sfc", checkedAt: "2026-10-16T13:06:00+02:00", overridden: false, blocks: true };

function game(over: Partial<AdminGame> = {}): AdminGame {
  return {
    id: "game-1",
    name: "Donkey Kong Country 3",
    slug: "dkc3",
    description: "",
    archipelagoDescription: null,
    coverImageUrl: null,
    coverImageAlt: "",
    coverImageCredit: "",
    availability: "available",
    disabled: false,
    disabledMessage: null,
    archipelagoGameName: "Donkey Kong Country 3",
    isYamlReady: true,
    isApworldReady: true,
    apworldHash: "07b2ffd4604a0000",
    apworldUploadedAt: "2026-06-04T18:48:00+02:00",
    defaultYaml: "name: Player\ngame: Donkey Kong Country 3\n",
    catalogSheetName: null,
    apworldSourceUrl: null,
    apworldDeployedVersion: null,
    apworldLatestVersion: null,
    apworldCheckedAt: null,
    apworldReleaseUrl: null,
    availabilityLocked: false,
    igdbId: null,
    platforms: [],
    installSteps: [],
    updateStatus: "not_tracked",
    adminNotes: null,
    ...over,
  };
}

function render(g: AdminGame): string {
  return renderToStaticMarkup(
    <QueryClientProvider client={new QueryClient()}>
      <ApworldTab game={g} onUpdate={() => undefined} />
    </QueryClientProvider>,
  );
}

/** Story 38.15: the APWorld tab, one card per subject, the one-off actions behind a modal. */
describe("ApworldTab", () => {
  test("a game with its apworld shows the live version, its test actions and the folded template", () => {
    const html = render(game({ apworldPreflight: failed }));

    expect(html).toContain("Version en ligne");
    expect(html).toContain("Tester la génération");
    expect(html).toContain("Remplacer");
    expect(html).toContain("Autoriser malgré l&#x27;échec");
    expect(html).toContain("Échec du test de génération");
    expect(html).toContain("Template YAML");
    expect(html).toContain('aria-expanded="false"');
    expect(html).not.toContain("Nouvelle version");
  });

  test("a game without apworld only offers to add one", () => {
    const html = render(game({ isApworldReady: false, apworldHash: null, defaultYaml: null }));

    expect(html).toContain("Ajouter un apworld");
    expect(html).not.toContain("Tester la génération");
    expect(html).not.toContain("Template YAML");
  });

  test("a candidate gets its own card with the YAML test behind a button", () => {
    const html = render(
      game({
        apworldCandidate: { id: "c", status: "testing", apworldHash: "h", versionTag: "v2", origin: "auto", submittedAt: "2026-10-16T10:00:00+02:00", decidedAt: null, rejectionReason: null },
      }),
    );

    expect(html).toContain("Nouvelle version");
    expect(html).toContain("Tester avec un YAML");
    expect(html).not.toContain("<textarea aria-label=\"YAML à tester\"");
  });

  test("the player's files keep their card", () => {
    expect(render(game({ requiredFiles: [rom] }))).toContain("Fichiers du joueur");
  });

  test("the verdict names the file a pass was obtained with", () => {
    expect(preflightVerdictLabel({ ...failed, status: "passed", withRequiredFiles: true }, { requiredFiles: [rom] }).label).toBe(
      "Test de génération réussi avec DKC3 (US) ROM File",
    );
    expect(preflightVerdictLabel(null, {}).label).toBe("Jamais testé");
  });
});
