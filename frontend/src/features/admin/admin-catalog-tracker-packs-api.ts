import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasNullableStringProp, hasStringProp } from "@/lib/type-guards";

/** Story 54.12: the PopTracker packs the community catalog names, proposed to the admin. */

export const catalogTrackerPacksQueryKey = (gameId: string) => ["admin-catalog-tracker-packs", gameId] as const;
export const gamesToEquipQueryKey = ["admin-catalog-tracker-packs", "games"] as const;

/** A pack the catalog names for a game. `importUrl` is null for a link that cannot be imported (not GitHub). */
export type CatalogTrackerPack = {
  label: string;
  url: string;
  importUrl: string | null;
  repository: string | null;
};

export type GameToEquip = {
  gameId: string;
  gameName: string;
  gameSlug: string;
  suggestions: CatalogTrackerPack[];
};

function isCatalogTrackerPack(v: unknown): v is CatalogTrackerPack {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "label") &&
    hasStringProp(v, "url") &&
    hasNullableStringProp(v, "importUrl") &&
    hasNullableStringProp(v, "repository")
  );
}

function isGameToEquip(v: unknown): v is GameToEquip {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "gameId") &&
    hasStringProp(v, "gameName") &&
    hasStringProp(v, "gameSlug") &&
    "suggestions" in v &&
    Array.isArray(v.suggestions) &&
    v.suggestions.every(isCatalogTrackerPack)
  );
}

async function fetchList<T>(path: string, guard: (v: unknown) => v is T): Promise<T[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}${path}`);
    if (!res.ok) return null;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json) || !Array.isArray(json.data)) return null;
    return json.data.every(guard) ? json.data : null;
  } catch {
    return null;
  }
}

/** The catalog's packs for a game, minus the ones imported; null on failure. Both mean nothing to show. */
export function fetchCatalogTrackerPacks(gameId: string): Promise<CatalogTrackerPack[] | null> {
  return fetchList(`/admin/games/${encodeURIComponent(gameId)}/tracker-pack-suggestions`, isCatalogTrackerPack);
}

/** The games the catalog names an importable pack for, not imported yet. */
export function fetchGamesToEquip(): Promise<GameToEquip[] | null> {
  return fetchList("/admin/tracker-pack-suggestions", isGameToEquip);
}
