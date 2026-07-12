import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

export type IgdbSearchEntry = {
  igdbId: number;
  name: string;
  slug: string;
  summary: string | null;
  coverUrl: string | null;
};

export type IgdbSearchPage = {
  results: IgdbSearchEntry[];
  hasMore: boolean;
};

// Null on any failure: both IGDB pickers render their generic search-error line.
// Never throws (AC-API2) - the old handlers were one-shot too.
export async function searchIgdbGames(
  query: string,
  offset: number,
  signal?: AbortSignal,
): Promise<IgdbSearchPage | null> {
  try {
    const res = await apiFetch(
      `${env.apiBaseUrl}/admin/igdb/search?q=${encodeURIComponent(query)}&offset=${offset}`,
      { signal },
    );
    if (!res.ok) return null;

    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload) || !("meta" in payload)) {
      return null;
    }
    if (!Array.isArray(payload.data)) return null;
    const meta: unknown = payload.meta;
    if (typeof meta !== "object" || meta === null || !("hasMore" in meta) || typeof meta.hasMore !== "boolean") {
      return null;
    }

    const rawItems: unknown[] = payload.data;
    const results = rawItems
      .filter((item): item is { igdbId: number; name: string } & Record<string, unknown> => {
        if (typeof item !== "object" || item === null) return false;
        if (!("igdbId" in item) || typeof item.igdbId !== "number") return false;
        return "name" in item && typeof item.name === "string";
      })
      .map((item) => ({
        igdbId: item.igdbId,
        name: item.name,
        slug: typeof item.slug === "string" ? item.slug : "",
        summary: typeof item.summary === "string" ? item.summary : null,
        coverUrl: typeof item.coverUrl === "string" ? item.coverUrl : null,
      }));

    return { results, hasMore: meta.hasMore };
  } catch {
    return null;
  }
}
