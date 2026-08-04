"use client";

import { Bookmark, BookmarkCheck } from "lucide-react";

import { useGameList } from "./use-game-list";

/**
 * The player's "à essayer" list, on a game page (story 28.14).
 *
 * Deliberately independent of the ownership badge next to it: owning a game says nothing about
 * having played it, so the two can both be on. Nothing here reads or writes the Steam coupling -
 * wanting a game is not something a store can answer.
 *
 * Signed out there is nothing to offer, as with the manual ownership list.
 */
export function GamePlannedButton({ gameId }: { gameId: string }) {
  const { isInList, canMark, toggle, pending } = useGameList("planned");

  if (!canMark) {
    return null;
  }

  const planned = isInList(gameId);

  return (
    <button
      className={
        planned
          ? "inline-flex items-center gap-1.5 rounded border border-accent/50 bg-accent/10 px-2.5 py-1 text-sm font-semibold text-accent-text transition-colors hover:border-accent disabled:opacity-50"
          : "inline-flex items-center gap-1.5 rounded border border-border bg-surface px-2.5 py-1 text-sm font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground disabled:opacity-50"
      }
      disabled={pending}
      onClick={() => toggle(gameId, !planned)}
      title={planned ? "Retirer de tes jeux à essayer" : "Ajouter à tes jeux à essayer, pour le filtre du catalogue"}
      type="button"
    >
      {planned ? (
        <BookmarkCheck aria-hidden="true" className="size-4" />
      ) : (
        <Bookmark aria-hidden="true" className="size-4" />
      )}
      À essayer
    </button>
  );
}
