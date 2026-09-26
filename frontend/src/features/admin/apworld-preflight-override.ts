import type { ApworldPreflight } from "./admin-games-api";

/**
 * Une dérogation n'autorise quelque chose que sur un test échoué (story 38.10) : c'est là qu'elle rend
 * le jeu sélectionnable malgré le verdict. Sur un test réussi, en cours ou sauté, elle n'a rien à
 * autoriser, et l'annoncer comme « active » induisait en erreur.
 */
export function overrideIsActive(preflight: ApworldPreflight | null): boolean {
  return preflight !== null && preflight.overridden && preflight.status === "failed";
}
