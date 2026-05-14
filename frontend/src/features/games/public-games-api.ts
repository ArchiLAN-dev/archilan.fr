import { env } from "@/lib/env";

export type PublicGame = {
  id: string;
  name: string;
  slug: string;
  description: string;
  coverImageUrl: string | null;
  coverImageAlt: string;
  availability: "available" | "experimental";
  supportedEventTypes: string[];
};

export type GamePage = {
  games: PublicGame[];
  total: number;
  page: number;
  perPage: number;
  totalPages: number;
};

export async function getPublicGames(query = "", page = 1): Promise<GamePage> {
  const empty: GamePage = { games: [], total: 0, page: 1, perPage: 24, totalPages: 1 };

  try {
    const params = new URLSearchParams();
    if (query) params.set("q", query);
    if (page > 1) params.set("page", String(page));

    const url = `${env.apiBaseUrl}/games${params.size > 0 ? `?${params}` : ""}`;
    const response = await fetch(url, { cache: "no-store" });

    if (!response.ok) return empty;

    const payload: unknown = await response.json();
    if (!isGamePagePayload(payload)) return empty;

    return {
      games: payload.data.map((g) => ({
        ...g,
        supportedEventTypes: Array.isArray(g.supportedEventTypes) ? g.supportedEventTypes : [],
      })),
      total: payload.meta.total,
      page: payload.meta.page,
      perPage: payload.meta.perPage,
      totalPages: payload.meta.totalPages,
    };
  } catch {
    return empty;
  }
}

function isGamePagePayload(
  payload: unknown,
): payload is { data: PublicGame[]; meta: { total: number; page: number; perPage: number; totalPages: number } } {
  if (!payload || typeof payload !== "object") return false;
  const p = payload as Record<string, unknown>;
  return Array.isArray(p.data) && typeof p.meta === "object" && p.meta !== null;
}
