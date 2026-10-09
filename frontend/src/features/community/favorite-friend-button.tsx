"use client";

import { useState } from "react";
import { Star } from "lucide-react";

import { favoriteFriend, unfavoriteFriend, type Relationship } from "./community-friends-api";

export const FAVORITE_LIMIT = 15;

/**
 * The star of a friend (story 43.11a): only the viewer sees it. `onChange` gets the new relationship; a refused star
 * (past the limit) leaves it as it was and says why.
 */
export function FavoriteFriendButton({
  slug,
  name,
  favorite,
  onChange,
  compact = false,
}: {
  slug: string;
  name: string;
  favorite: boolean;
  onChange: (next: Relationship) => void | Promise<void>;
  compact?: boolean;
}) {
  const [busy, setBusy] = useState(false);
  const [refused, setRefused] = useState(false);

  async function toggle() {
    setBusy(true);
    const next = favorite ? await unfavoriteFriend(slug) : await favoriteFriend(slug);
    setBusy(false);
    setRefused(next === null && !favorite);
    if (next !== null) await onChange(next);
  }

  const label = favorite ? `Retirer ${name} des favoris` : `Mettre ${name} en favori`;
  const star = <Star aria-hidden className={`size-4 ${favorite ? "fill-warning text-warning" : ""}`} />;

  return (
    <span className="inline-flex items-center gap-2">
      <button
        aria-label={compact ? label : undefined}
        aria-pressed={favorite}
        className={
          compact
            ? "inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors hover:text-warning disabled:opacity-50"
            : "inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-full border border-border px-3.5 text-sm font-medium text-foreground transition-colors hover:border-accent disabled:opacity-50"
        }
        disabled={busy}
        onClick={() => {
          void toggle();
        }}
        title={compact ? label : undefined}
        type="button"
      >
        {star}
        {compact ? null : favorite ? "Favori" : "Mettre en favori"}
      </button>
      {refused ? (
        <span className="text-xs text-muted-foreground" role="status">
          {FAVORITE_LIMIT} favoris au maximum
        </span>
      ) : null}
    </span>
  );
}
