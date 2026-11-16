import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/** The PopTracker packs players are offered for a game (story 54.2). */

export type TrackerPackVariant = { uid: string; displayName: string; flags: string[] };

export type TrackerPackVersion = {
  id: string;
  packageVersion: string | null;
  /** Folder of the zip holding manifest.json, "" at its root. */
  rootPath: string;
  sizeBytes: number;
  sha256: string;
  variants: TrackerPackVariant[];
};

export type GameTrackerPack = {
  id: string;
  name: string;
  author: string | null;
  licenseSpdx: string | null;
  sourceUrl: string;
  isDefault: boolean;
  hasApVariant: boolean;
  /** Null: the first variant of the version. */
  defaultVariantUid: string | null;
  version: TrackerPackVersion;
};

function isVariant(v: unknown): v is TrackerPackVariant {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "uid") &&
    hasStringProp(v, "displayName") &&
    "flags" in v &&
    Array.isArray(v.flags) &&
    v.flags.every((flag) => typeof flag === "string")
  );
}

function isTrackerPackVersion(v: unknown): v is TrackerPackVersion {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "id") || !hasNullableStringProp(v, "packageVersion") || !hasStringProp(v, "rootPath")) return false;
  if (!hasNumberProp(v, "sizeBytes") || !hasStringProp(v, "sha256")) return false;
  return "variants" in v && Array.isArray(v.variants) && v.variants.every(isVariant);
}

export function isGameTrackerPack(v: unknown): v is GameTrackerPack {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "id") || !hasStringProp(v, "name") || !hasStringProp(v, "sourceUrl")) return false;
  if (!hasNullableStringProp(v, "author") || !hasNullableStringProp(v, "licenseSpdx") || !hasNullableStringProp(v, "defaultVariantUid")) {
    return false;
  }
  if (!hasBooleanProp(v, "isDefault") || !hasBooleanProp(v, "hasApVariant")) return false;
  return "version" in v && isTrackerPackVersion(v.version);
}

/** Server side, cached like the game page. An unreachable API means no tracker block, never a broken page. */
export async function getGameTrackerPacks(gameId: string): Promise<GameTrackerPack[]> {
  try {
    const response = await fetch(`${env.apiBaseUrl}/games/${encodeURIComponent(gameId)}/tracker-packs`, {
      next: { revalidate: 300 },
    });
    if (!response.ok) return [];
    const payload: unknown = await response.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload) || !Array.isArray(payload.data)) return [];
    return payload.data.filter(isGameTrackerPack);
  } catch {
    return [];
  }
}
