import type { PersonalRunStatus } from "./types";

/** États où le propriétaire et les participants ont la carte « mes jeux » à la place. */
const OWN_CARD_STATUSES: PersonalRunStatus[] = ["draft", "idle"];

/**
 * Faut-il montrer la ligne d'état de la vue d'ensemble à ce lecteur ?
 *
 * Tous les blocs de la vue d'ensemble sont gardés sur « propriétaire ou participant ». La ligne
 * d'état est le repli pour les autres, et elle excluait `draft` et `idle` - parce que la carte
 * « mes jeux » couvre ces deux états, pour ceux qui l'ont.
 *
 * Un administrateur qui ouvre une partie dont il ne fait pas partie n'est ni l'un ni l'autre : sur
 * une partie en brouillon, sa vue d'ensemble était entièrement vide.
 *
 * Extrait du composant pour que la règle soit testable telle qu'elle est appliquée, plutôt que
 * recopiée dans un test qui vérifierait sa copie.
 */
export function showsRunStatusLine(
  isOwner: boolean,
  isParticipant: boolean,
  status: PersonalRunStatus,
): boolean {
  if (isOwner) return false;

  return !isParticipant || !OWN_CARD_STATUSES.includes(status);
}
