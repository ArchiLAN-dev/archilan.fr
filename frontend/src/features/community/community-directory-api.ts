import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

export type DirectoryMode = "top" | "recent" | "friends";

export type DirectoryRow = {
  slug: string;
  displayName: string | null;
  avatarUrl: string | null;
  level: number;
  xp: number;
  xpIntoLevel: number;
  xpForNextLevel: number;
  playing: boolean;
};

export type DirectoryResult = { rows: DirectoryRow[]; total: number; page: number; perPage: number };

export type DirectoryParams = { mode: DirectoryMode; search: string; page: number };

function isRow(v: unknown): v is DirectoryRow {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "slug") &&
    hasNullableStringProp(v, "displayName") &&
    hasNullableStringProp(v, "avatarUrl") &&
    hasNumberProp(v, "level") &&
    hasNumberProp(v, "xp") &&
    hasNumberProp(v, "xpIntoLevel") &&
    hasNumberProp(v, "xpForNextLevel") &&
    hasBooleanProp(v, "playing")
  );
}

function directoryUrl(params: DirectoryParams): string {
  const query = new URLSearchParams({ mode: params.mode, page: String(params.page) });
  if (params.search.trim() !== "") query.set("search", params.search.trim());

  return `${env.apiBaseUrl}/community/directory?${query.toString()}`;
}

function parseDirectory(json: unknown, page: number): DirectoryResult | null {
  if (typeof json !== "object" || json === null || !("data" in json) || !Array.isArray(json.data)) return null;
  if (!json.data.every(isRow)) return null;

  const meta: unknown = "meta" in json ? json.meta : null;
  const total = typeof meta === "object" && meta !== null && hasNumberProp(meta, "total") ? meta.total : json.data.length;
  const perPage = typeof meta === "object" && meta !== null && hasNumberProp(meta, "perPage") ? meta.perPage : json.data.length;

  return { rows: json.data, total, page, perPage };
}

/** Client-side read: goes through apiFetch so "Mes amis" carries the session cookie. */
export async function fetchDirectory(params: DirectoryParams): Promise<DirectoryResult | null> {
  try {
    const res = await apiFetch(directoryUrl(params));
    if (!res.ok) return null;
    return parseDirectory(await res.json(), params.page);
  } catch {
    return null;
  }
}

/**
 * Server-side read for the hub's members preview (story 30.38): a plain anonymous fetch, since apiFetch's
 * 401/refresh path is browser-only (localStorage, Web Locks) and there is no session to carry here.
 */
export async function fetchDirectoryServerSide(params: DirectoryParams): Promise<DirectoryResult | null> {
  try {
    const res = await fetch(directoryUrl(params), { cache: "no-store" });
    if (!res.ok) return null;
    return parseDirectory(await res.json(), params.page);
  } catch {
    return null;
  }
}
