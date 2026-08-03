"use client";

import { Check, Plus } from "lucide-react";

import { useGameList } from "./use-game-list";
import { useSteamCoupling } from "./use-steam-coupling";

/**
 * Ownership of this game, and the control to declare it (stories 28.3, 28.13).
 *
 * Two sources, unioned. A coupled Steam library recognises the game on its own - nothing to click,
 * and nothing to undo, since that answer belongs to Steam. Everything else is the player's own
 * ArchiLAN list, which is the only way to claim a game that has no `steamAppId` at all: most of
 * this catalog is console-era and will never match a Steam library, however many are coupled.
 *
 * Signed out with no Steam match, there is nothing to show and nothing to offer.
 */
export function GameOwnedBadge({ gameId, steamAppId }: { gameId: string; steamAppId: number | null }) {
  const { matchedAppIds } = useSteamCoupling();
  const { isInList, canMark, toggle, pending } = useGameList("owned");

  const ownedViaSteam = steamAppId !== null && matchedAppIds.has(steamAppId);
  const ownedHere = isInList(gameId);

  if (ownedViaSteam) {
    return (
      <span className="inline-flex items-center gap-1.5 rounded border border-success/50 bg-success/10 px-2.5 py-1 text-sm font-semibold text-success">
        <Check aria-hidden="true" className="size-4" />
        Tu possèdes ce jeu
      </span>
    );
  }

  if (!canMark) {
    return null;
  }

  return (
    <button
      className={
        ownedHere
          ? "inline-flex items-center gap-1.5 rounded border border-success/50 bg-success/10 px-2.5 py-1 text-sm font-semibold text-success transition-colors hover:border-success disabled:opacity-50"
          : "inline-flex items-center gap-1.5 rounded border border-border bg-surface px-2.5 py-1 text-sm font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground disabled:opacity-50"
      }
      disabled={pending}
      onClick={() => toggle(gameId, !ownedHere)}
      title={ownedHere ? "Retirer de tes jeux" : "Ajouter à tes jeux, pour le filtre du catalogue"}
      type="button"
    >
      {ownedHere ? <Check aria-hidden="true" className="size-4" /> : <Plus aria-hidden="true" className="size-4" />}
      {ownedHere ? "Tu possèdes ce jeu" : "J'ai ce jeu"}
    </button>
  );
}
