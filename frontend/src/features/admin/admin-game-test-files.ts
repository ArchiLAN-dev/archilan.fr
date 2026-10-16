import type { ProvidedFileState } from "@/features/games/required-file-check";
import { asGameRequiredFiles, requiredFileLabel, type GameRequiredFile, type GenerationFile } from "@/features/games/required-files";
import type { ApworldPreflight } from "./admin-games-api";

/**
 * Story 44.6: the admin's test generations of a game whose world reads a file (a ROM). The file is asked the
 * way a launch asks it (story 44.5), but stays optional: without it the test runs and fails on its absence.
 */

/** What the file panel of an admin test says above the files. */
export const TEST_FILES_INTRO =
  "Le jeu lit ce fichier pendant la génération. Sans ce fichier, le test échouera. Il est vérifié ici et envoyé pour ce test seulement, jamais gardé.";

/** The files the game's tests should carry: those a failed generation proved necessary. */
export function testFilesOfGame(requiredFiles: unknown): GenerationFile[] {
  return asGameRequiredFiles(requiredFiles)
    .filter((file) => file.neededAtGeneration)
    .map((file) => ({ ...file, games: [] }));
}

/** Why the test cannot be sent as it stands: a file still being checked, or one that is not the right one. */
export function testFilesBlocker(states: Record<string, ProvidedFileState>): string | null {
  const values = Object.values(states);
  if (values.some((state) => state.kind === "checking")) return "Vérification du fichier en cours…";
  if (values.some((state) => state.kind === "wrong" || state.kind === "tooLarge")) {
    return "Le fichier choisi n'est pas le bon : change-le, ou retire-le en rechargeant la page.";
  }
  return null;
}

/** The verdict line of a pass obtained with the admin's file, or null for any other verdict. */
export function passedWithFilesLabel(
  preflight: Pick<ApworldPreflight, "status" | "withRequiredFiles">,
  files: GameRequiredFile[],
): string | null {
  if (preflight.status !== "passed" || preflight.withRequiredFiles !== true) return null;
  const labels = files.map(requiredFileLabel);
  return labels.length > 0 ? `Test de génération réussi avec ${labels.join(", ")}` : "Test de génération réussi avec le fichier fourni";
}
