import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

export type PublicGame = {
  id: string;
  name: string;
  slug: string;
  description: string;
  coverImageUrl: string | null;
  coverImageAlt: string;
  availability: "available" | "experimental";
  supportedEventTypes: string[];
  steamAppId: number | null;
  platforms: string[];
};

export type GamePage = {
  games: PublicGame[];
  total: number;
  page: number;
  perPage: number;
  totalPages: number;
};

export type GameOption = { key: string; min: number; max: number; default: number | null };
export type GameLink = { label: string; url: string | null };
export type GameApworld = {
  deployedVersion: string | null;
  latestVersion: string | null;
  sourceUrl: string | null;
  releaseUrl: string | null;
  updateStatus: string;
};

export type PublicGameDetail = PublicGame & {
  coverImageCredit: string;
  bundledWithAp: boolean;
  adultContent: boolean;
  apworld: GameApworld;
  options: GameOption[];
  catalog: { notes: string | null; links: GameLink[] };
};

export async function getAllPublicGames(): Promise<PublicGame[]> {
  try {
    const response = await fetch(`${env.apiBaseUrl}/games?all=1`, { cache: "no-store" });
    if (!response.ok) return [];

    const payload: unknown = await response.json();
    if (typeof payload !== "object" || payload === null) return [];
    if (!("data" in payload) || !Array.isArray(payload.data) || !payload.data.every(isPublicGame)) return [];

    return payload.data.map((g) => ({
      ...g,
      supportedEventTypes: Array.isArray(g.supportedEventTypes) ? g.supportedEventTypes : [],
    }));
  } catch {
    return [];
  }
}

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

export async function getPublicGame(slug: string): Promise<PublicGameDetail | null> {
  try {
    const response = await fetch(`${env.apiBaseUrl}/games/${encodeURIComponent(slug)}`, {
      cache: "no-store",
    });
    if (!response.ok) return null;

    const payload: unknown = await response.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return null;
    if (!isPublicGameDetail(payload.data)) return null;

    return payload.data;
  } catch {
    return null;
  }
}

function isGameAvailability(v: unknown): v is PublicGame["availability"] {
  return v === "available" || v === "experimental";
}

function isGameOption(v: unknown): v is GameOption {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "key") || !hasNumberProp(v, "min") || !hasNumberProp(v, "max")) return false;
  return "default" in v && (v.default === null || typeof v.default === "number");
}

function isGameLink(v: unknown): v is GameLink {
  if (typeof v !== "object" || v === null) return false;
  return hasStringProp(v, "label") && hasNullableStringProp(v, "url");
}

function isGameApworld(v: unknown): v is GameApworld {
  if (typeof v !== "object" || v === null) return false;
  if (!hasNullableStringProp(v, "deployedVersion") || !hasNullableStringProp(v, "latestVersion")) return false;
  if (!hasNullableStringProp(v, "sourceUrl") || !hasNullableStringProp(v, "releaseUrl")) return false;
  return hasStringProp(v, "updateStatus");
}

function isPublicGameDetail(v: unknown): v is PublicGameDetail {
  if (!isPublicGame(v)) return false;
  if (!hasStringProp(v, "coverImageCredit")) return false;
  if (!hasBooleanProp(v, "bundledWithAp") || !hasBooleanProp(v, "adultContent")) return false;
  if (!("apworld" in v) || !isGameApworld(v.apworld)) return false;
  if (!("options" in v) || !Array.isArray(v.options) || !v.options.every(isGameOption)) return false;
  if (!("catalog" in v) || typeof v.catalog !== "object" || v.catalog === null) return false;
  if (!hasNullableStringProp(v.catalog, "notes")) return false;
  return "links" in v.catalog && Array.isArray(v.catalog.links) && v.catalog.links.every(isGameLink);
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
  if (!("steamAppId" in v) || (v.steamAppId !== null && typeof v.steamAppId !== "number")) return false;
  if (!("platforms" in v) || !Array.isArray(v.platforms) || !v.platforms.every((item): item is string => typeof item === "string")) return false;
  return "supportedEventTypes" in v && Array.isArray(v.supportedEventTypes) && v.supportedEventTypes.every((item): item is string => typeof item === "string");
}
