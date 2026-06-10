import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasNumberProp } from "@/lib/type-guards";

export type AdminGame = {
  id: string;
  name: string;
  slug: string;
  coverImageUrl: string | null;
  availability: "available" | "unavailable" | "experimental";
  isYamlReady: boolean;
  usageCount: number;
};

export type GameSort = "name" | "usage";
export type GameSortDir = "asc" | "desc";

export type AdminGameListFilters = {
  page: number;
  perPage: number;
  search: string;
  availability: "available" | "unavailable" | "experimental" | "";
  yamlReady: "" | "1" | "0";
  sort: GameSort;
  dir: GameSortDir;
};

type AdminGameListPayload = {
  data: AdminGame[];
  meta: { total: number; page: number; perPage: number; totalPages: number };
};

export async function fetchAdminGames(
  filters: AdminGameListFilters,
): Promise<AdminGameListPayload | null> {
  try {
    const params = new URLSearchParams({
      page: String(filters.page),
      per_page: String(filters.perPage),
    });
    if (filters.search.trim()) params.set("search", filters.search.trim());
    if (filters.availability) params.set("availability", filters.availability);
    if (filters.yamlReady) params.set("yaml_ready", filters.yamlReady);
    params.set("sort", filters.sort);
    params.set("dir", filters.dir);

    const res = await apiFetch(`${env.apiBaseUrl}/admin/games?${params.toString()}`);

    if (res.status === 401 || res.status === 403) return null;
    if (!res.ok) return null;

    const payload: unknown = await res.json();
    return isAdminGameListPayload(payload) ? payload : null;
  } catch {
    return null;
  }
}

function isAdminGameListPayload(payload: unknown): payload is AdminGameListPayload {
  if (!payload || typeof payload !== "object") return false;
  if (!("data" in payload) || !Array.isArray(payload.data)) return false;
  if (!("meta" in payload) || !payload.meta || typeof payload.meta !== "object") return false;
  return hasNumberProp(payload.meta, "total");
}
