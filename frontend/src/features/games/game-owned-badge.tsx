"use client";

import { useSteamCoupling } from "./use-steam-coupling";

/**
 * Shows the "owned" badge on the game detail page when the visitor's coupled Steam
 * library (saved account or localStorage) contains this game. Reuses the same coupling
 * state as the /jeux catalog - no extra request beyond the shared auto-coupling.
 */
export function GameOwnedBadge({ steamAppId }: { steamAppId: number }) {
  const { matchedAppIds } = useSteamCoupling();

  if (!matchedAppIds.has(steamAppId)) {
    return null;
  }

  return (
    <span className="inline-flex items-center rounded border border-success/50 bg-success/10 px-2.5 py-1 text-sm font-semibold text-success">
      Tu possèdes ce jeu
    </span>
  );
}