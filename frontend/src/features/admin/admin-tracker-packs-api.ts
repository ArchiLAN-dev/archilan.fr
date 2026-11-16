import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/** Client of the admin API of a game's PopTracker packs (story 54.1). */

export const trackerPacksQueryKey = (gameId: string) => ["admin-tracker-packs", gameId] as const;

export type TrackerPackVariant = { uid: string; displayName: string; flags: string[] };

export type AdminTrackerPack = {
  id: string;
  name: string;
  author: string | null;
  packageUid: string;
  gameName: string | null;
  sourceUrl: string;
  licenseSpdx: string | null;
  hasApVariant: boolean;
  versionId: string;
  packageVersion: string | null;
  gitTag: string | null;
  sizeBytes: number;
  variants: TrackerPackVariant[];
  minPopTrackerVersion: string | null;
  importedAt: string;
};

export type ImportTrackerPackResult =
  | { ok: true; name: string; packageVersion: string | null; hasApVariant: boolean }
  | { ok: false; message: string };

const GENERIC_IMPORT_ERROR = "L'import a échoué, réessaie dans un instant.";

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

export function isAdminTrackerPack(v: unknown): v is AdminTrackerPack {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "id") || !hasStringProp(v, "name") || !hasStringProp(v, "packageUid")) return false;
  if (!hasStringProp(v, "sourceUrl") || !hasStringProp(v, "versionId") || !hasStringProp(v, "importedAt")) return false;
  if (!hasNullableStringProp(v, "author") || !hasNullableStringProp(v, "gameName") || !hasNullableStringProp(v, "licenseSpdx")) {
    return false;
  }
  if (!hasNullableStringProp(v, "packageVersion") || !hasNullableStringProp(v, "gitTag")) return false;
  if (!hasNullableStringProp(v, "minPopTrackerVersion") || !hasBooleanProp(v, "hasApVariant") || !hasNumberProp(v, "sizeBytes")) {
    return false;
  }
  return "variants" in v && Array.isArray(v.variants) && v.variants.every(isVariant);
}

export async function fetchTrackerPacks(gameId: string): Promise<AdminTrackerPack[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${encodeURIComponent(gameId)}/tracker-packs`);
    if (!res.ok) return null;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json) || !Array.isArray(json.data)) return null;
    return json.data.every(isAdminTrackerPack) ? json.data : null;
  } catch {
    return null;
  }
}

export async function importTrackerPack(gameId: string, url: string): Promise<ImportTrackerPackResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/games/${encodeURIComponent(gameId)}/tracker-packs`, {
      body: JSON.stringify({ url }),
      headers: { "Content-Type": "application/json" },
      method: "POST",
    });
    const json: unknown = await res.json().catch(() => null);
    if (res.ok) {
      const data: unknown = typeof json === "object" && json !== null && "data" in json ? json.data : null;
      if (
        typeof data === "object" &&
        data !== null &&
        hasStringProp(data, "name") &&
        hasNullableStringProp(data, "packageVersion") &&
        hasBooleanProp(data, "hasApVariant")
      ) {
        return { ok: true, name: data.name, packageVersion: data.packageVersion, hasApVariant: data.hasApVariant };
      }
      return { ok: false, message: GENERIC_IMPORT_ERROR };
    }
    const error: unknown = typeof json === "object" && json !== null && "error" in json ? json.error : null;
    return {
      ok: false,
      message: typeof error === "object" && error !== null && hasStringProp(error, "message") ? error.message : GENERIC_IMPORT_ERROR,
    };
  } catch {
    return { ok: false, message: GENERIC_IMPORT_ERROR };
  }
}

/** A size in the unit an admin reads at a glance. */
export function formatPackSize(bytes: number): string {
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} Ko`;
  return `${(bytes / (1024 * 1024)).toFixed(1).replace(".", ",")} Mo`;
}
