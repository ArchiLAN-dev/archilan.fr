import { env } from "@/lib/env";
import { hasNumberProp, hasStringProp } from "@/lib/type-guards";

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
  if (typeof payload !== "object" || payload === null) return false;
  if (!("data" in payload) || !Array.isArray(payload.data) || !payload.data.every(isPublicGame)) return false;
  if (!("meta" in payload) || typeof payload.meta !== "object" || payload.meta === null) return false;
  return (
    hasNumberProp(payload.meta, "total") &&
    hasNumberProp(payload.meta, "page") &&
    hasNumberProp(payload.meta, "perPage") &&
    hasNumberProp(payload.meta, "totalPages")
  );
}

function isGameAvailability(v: unknown): v is PublicGame["availability"] {
  return v === "available" || v === "experimental";
}

function isPublicGame(v: unknown): v is PublicGame {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "id")) return false;
  if (!hasStringProp(v, "name")) return false;
  if (!hasStringProp(v, "slug")) return false;
  if (!hasStringProp(v, "description")) return false;
  if (!("coverImageUrl" in v) || (v.coverImageUrl !== null && typeof v.coverImageUrl !== "string")) return false;
  if (!hasStringProp(v, "coverImageAlt")) return false;
  if (!("availability" in v) || !isGameAvailability(v.availability)) return false;
  return "supportedEventTypes" in v && Array.isArray(v.supportedEventTypes) && v.supportedEventTypes.every((item): item is string => typeof item === "string");
}
