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

/** Story 54.7: the same list from the browser, for the « Ouvrir le tracker » button of a slot. */
export async function fetchGameTrackerPacks(gameId: string): Promise<GameTrackerPack[]> {
  try {
    const response = await fetch(`${env.apiBaseUrl}/games/${encodeURIComponent(gameId)}/tracker-packs`);
    if (!response.ok) return [];
    const payload: unknown = await response.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload) || !Array.isArray(payload.data)) return [];
    return payload.data.filter(isGameTrackerPack);
  } catch {
    return [];
  }
}

/** Story 54.5: archives already downloaded, by version (a version's zip never changes). */
const ARCHIVE_CACHE = "tracker-packs";
/** Past this many versions in the cache, the ones unused for the longest go. */
export const ARCHIVE_CACHE_LIMIT = 5;

export function trackerArchiveUrl(versionId: string): string {
  return `${env.apiBaseUrl}/tracker-pack-versions/${encodeURIComponent(versionId)}/archive`;
}

/** `loaded` of `total` bytes; `total` is null when the server does not say. */
export type ArchiveProgress = (loaded: number, total: number | null) => void;

async function openArchiveCache(): Promise<Cache | null> {
  try {
    return typeof caches === "undefined" ? null : await caches.open(ARCHIVE_CACHE);
  } catch {
    return null;
  }
}

async function readWithProgress(response: Response, onProgress: ArchiveProgress): Promise<ArrayBuffer> {
  const header = response.headers.get("Content-Length");
  const total = header !== null && Number(header) > 0 ? Number(header) : null;
  if (response.body === null) return response.arrayBuffer();
  const reader = response.body.getReader();
  const chunks: Uint8Array[] = [];
  let loaded = 0;
  for (;;) {
    const { done, value } = await reader.read();
    if (done) break;
    chunks.push(value);
    loaded += value.byteLength;
    onProgress(loaded, total);
  }
  const bytes = new Uint8Array(loaded);
  let offset = 0;
  for (const chunk of chunks) {
    bytes.set(chunk, offset);
    offset += chunk.byteLength;
  }
  return bytes.buffer;
}

/**
 * Keeps the archive cache to the most recently used versions: a hit is put back at the end of the cache, whose keys
 * come in insertion order.
 */
async function rememberArchive(cache: Cache, url: string, bytes: ArrayBuffer): Promise<void> {
  try {
    await cache.delete(url);
    await cache.put(url, new Response(bytes, { headers: { "Content-Type": "application/zip" } }));
    const keys = await cache.keys();
    for (const stale of keys.slice(0, Math.max(0, keys.length - ARCHIVE_CACHE_LIMIT))) await cache.delete(stale);
  } catch {
    // A full or refused cache only means downloading again next time.
  }
}

/**
 * The zip of a pack version, from the browser cache or the API. Null when it cannot be had; never throws.
 */
export async function fetchTrackerArchive(versionId: string, onProgress: ArchiveProgress): Promise<ArrayBuffer | null> {
  const url = trackerArchiveUrl(versionId);
  const cache = await openArchiveCache();
  try {
    const cached = cache === null ? undefined : await cache.match(url);
    if (cached !== undefined) {
      const bytes = await cached.arrayBuffer();
      onProgress(bytes.byteLength, bytes.byteLength);
      if (cache !== null) await rememberArchive(cache, url, bytes.slice(0));
      return bytes;
    }
  } catch {
    // Fall back to the network.
  }
  try {
    // Story 54.8: with the session, so an admin trying a pack not yet offered gets its zip.
    const response = await fetch(url, { credentials: "include" });
    if (!response.ok) return null;
    const bytes = await readWithProgress(response, onProgress);
    // The buffer goes to the Worker and comes back detached: the cache keeps its own copy.
    if (cache !== null) await rememberArchive(cache, url, bytes.slice(0));
    return bytes;
  } catch {
    return null;
  }
}
