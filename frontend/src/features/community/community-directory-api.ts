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
    hasBooleanProp(v, "playing")
  );
}

export async function fetchDirectory(params: DirectoryParams): Promise<DirectoryResult | null> {
  try {
    const query = new URLSearchParams({ mode: params.mode, page: String(params.page) });
    if (params.search.trim() !== "") query.set("search", params.search.trim());

    const res = await apiFetch(`${env.apiBaseUrl}/community/directory?${query.toString()}`);
    if (!res.ok) return null;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json) || !Array.isArray(json.data)) return null;
    if (!json.data.every(isRow)) return null;

    const meta: unknown = "meta" in json ? json.meta : null;
    const total = typeof meta === "object" && meta !== null && hasNumberProp(meta, "total") ? meta.total : json.data.length;
    const perPage = typeof meta === "object" && meta !== null && hasNumberProp(meta, "perPage") ? meta.perPage : json.data.length;

    return { rows: json.data, total, page: params.page, perPage };
  } catch {
    return null;
  }
}
