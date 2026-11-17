import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/** Client of the admin API of a game's PopTracker packs (stories 54.1 to 54.3). */

export const trackerPacksQueryKey = (gameId: string) => ["admin-tracker-packs", gameId] as const;

export type TrackerPackVariant = { uid: string; displayName: string; flags: string[] };

/** Story 54.3: a newer version the check found, waiting for an admin. */
export type TrackerPackUpdate = {
  packageVersion: string;
  downloadUrl: string;
  sha256: string | null;
  changelog: string[];
  gitTag: string | null;
};

/** Story 54.3: a version the pack keeps, newest first. */
export type TrackerPackKeptVersion = {
  id: string;
  packageVersion: string | null;
  gitTag: string | null;
  sizeBytes: number;
  importedAt: string;
  current: boolean;
};

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
  /** Story 54.2: offered to players. */
  enabled: boolean;
  isDefault: boolean;
  /** Null: the first variant of the manifest. */
  defaultVariantUid: string | null;
  licenseAcknowledged: boolean;
  availableUpdate: TrackerPackUpdate | null;
  lastCheckedAt: string | null;
  lastCheckError: string | null;
  versions: TrackerPackKeptVersion[];
};

/** Story 54.2: what an admin changes on a pack. Absent fields are left as they are. */
export type TrackerPackChanges = {
  enabled?: boolean;
  isDefault?: true;
  defaultVariantUid?: string | null;
  licenseAcknowledged?: boolean;
};

export type TrackerPackActionResult = { ok: true } | { ok: false; message: string };

/** Story 54.3: the version a pack serves after an update or a way back. */
export type ServedVersionResult =
  | { ok: true; packageVersion: string | null; defaultVariantReset: boolean }
  | { ok: false; message: string };

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

function isTrackerPackUpdate(v: unknown): v is TrackerPackUpdate {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "packageVersion") &&
    hasStringProp(v, "downloadUrl") &&
    hasNullableStringProp(v, "sha256") &&
    hasNullableStringProp(v, "gitTag") &&
    "changelog" in v &&
    Array.isArray(v.changelog) &&
    v.changelog.every((line) => typeof line === "string")
  );
}

function isKeptVersion(v: unknown): v is TrackerPackKeptVersion {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "id") &&
    hasNullableStringProp(v, "packageVersion") &&
    hasNullableStringProp(v, "gitTag") &&
    hasNumberProp(v, "sizeBytes") &&
    hasStringProp(v, "importedAt") &&
    hasBooleanProp(v, "current")
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
  if (!hasBooleanProp(v, "enabled") || !hasBooleanProp(v, "isDefault") || !hasBooleanProp(v, "licenseAcknowledged")) return false;
  if (!hasNullableStringProp(v, "defaultVariantUid")) return false;
  if (!hasNullableStringProp(v, "lastCheckedAt") || !hasNullableStringProp(v, "lastCheckError")) return false;
  if (!("availableUpdate" in v) || (v.availableUpdate !== null && !isTrackerPackUpdate(v.availableUpdate))) return false;
  if (!("versions" in v) || !Array.isArray(v.versions) || !v.versions.every(isKeptVersion)) return false;
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

const GENERIC_UPDATE_ERROR = "Le changement n'a pas été enregistré, réessaie dans un instant.";

export async function updateTrackerPack(packId: string, changes: TrackerPackChanges): Promise<TrackerPackActionResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/tracker-packs/${encodeURIComponent(packId)}`, {
      body: JSON.stringify(changes),
      headers: { "Content-Type": "application/json" },
      method: "PATCH",
    });
    if (res.ok) return { ok: true };
    const json: unknown = await res.json().catch(() => null);
    const error: unknown = typeof json === "object" && json !== null && "error" in json ? json.error : null;
    return {
      ok: false,
      message: typeof error === "object" && error !== null && hasStringProp(error, "message") ? error.message : GENERIC_UPDATE_ERROR,
    };
  } catch {
    return { ok: false, message: GENERIC_UPDATE_ERROR };
  }
}

function errorMessage(json: unknown, fallback: string): string {
  const error: unknown = typeof json === "object" && json !== null && "error" in json ? json.error : null;
  return typeof error === "object" && error !== null && hasStringProp(error, "message") ? error.message : fallback;
}

const GENERIC_CHECK_ERROR = "La vérification a échoué, réessaie dans un instant.";
const GENERIC_SERVE_ERROR = "Le changement de version a échoué, réessaie dans un instant.";

/** Story 54.3: looks for a newer version of the pack now. */
export async function checkTrackerPackUpdate(packId: string): Promise<TrackerPackActionResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/tracker-packs/${encodeURIComponent(packId)}/check-update`, { method: "POST" });
    if (res.ok) return { ok: true };
    return { ok: false, message: errorMessage(await res.json().catch(() => null), GENERIC_CHECK_ERROR) };
  } catch {
    return { ok: false, message: GENERIC_CHECK_ERROR };
  }
}

async function serveVersion(url: string, body: Record<string, string>): Promise<ServedVersionResult> {
  try {
    const res = await apiFetch(url, { body: JSON.stringify(body), headers: { "Content-Type": "application/json" }, method: "POST" });
    const json: unknown = await res.json().catch(() => null);
    if (!res.ok) return { ok: false, message: errorMessage(json, GENERIC_SERVE_ERROR) };
    const data: unknown = typeof json === "object" && json !== null && "data" in json ? json.data : null;
    if (typeof data === "object" && data !== null && hasNullableStringProp(data, "packageVersion") && hasBooleanProp(data, "defaultVariantReset")) {
      return { ok: true, packageVersion: data.packageVersion, defaultVariantReset: data.defaultVariantReset };
    }
    return { ok: false, message: GENERIC_SERVE_ERROR };
  } catch {
    return { ok: false, message: GENERIC_SERVE_ERROR };
  }
}

/** Story 54.3: installs the update found, or the version at a GitHub link. */
export function upgradeTrackerPack(packId: string, url?: string): Promise<ServedVersionResult> {
  return serveVersion(`${env.apiBaseUrl}/admin/tracker-packs/${encodeURIComponent(packId)}/update`, url === undefined ? {} : { url });
}

/** Story 54.3: serves again a version the pack kept. */
export function rollbackTrackerPack(packId: string, versionId: string): Promise<ServedVersionResult> {
  return serveVersion(`${env.apiBaseUrl}/admin/tracker-packs/${encodeURIComponent(packId)}/rollback`, { versionId });
}

/** A size in the unit an admin reads at a glance. */
export function formatPackSize(bytes: number): string {
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} Ko`;
  return `${(bytes / (1024 * 1024)).toFixed(1).replace(".", ",")} Mo`;
}
