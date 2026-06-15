import { env } from "@/lib/env";
import { hasNumberProp, hasStringProp } from "@/lib/type-guards";

export type CoupledGame = {
  id: string;
  name: string;
  slug: string;
  coverImageUrl: string | null;
  availability: string;
  steamAppId: number;
};

export type CouplingOutcome = "ok" | "private_profile" | "invalid_input" | "steam_error";

export type CouplingResult = {
  outcome: CouplingOutcome;
  matchedGames: CoupledGame[];
  ownedCount: number;
  matchedCount: number;
};

function empty(outcome: CouplingOutcome): CouplingResult {
  return { outcome, matchedGames: [], ownedCount: 0, matchedCount: 0 };
}

export async function coupleSteamLibrary(steamProfile: string): Promise<CouplingResult> {
  try {
    const res = await fetch(`${env.apiBaseUrl}/games/steam-coupling`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ steamProfile }),
    });

    if (res.status === 422) return empty("invalid_input");
    if (!res.ok) return empty("steam_error");

    const json: unknown = await res.json();
    return parseCouplingResponse(json);
  } catch {
    return empty("steam_error");
  }
}

function isCoupledGame(v: unknown): v is CoupledGame {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "id") || !hasStringProp(v, "name") || !hasStringProp(v, "slug")) return false;
  if (!hasStringProp(v, "availability")) return false;
  if (!hasNumberProp(v, "steamAppId")) return false;
  return "coverImageUrl" in v && (v.coverImageUrl === null || typeof v.coverImageUrl === "string");
}

function isOutcome(v: unknown): v is CouplingOutcome {
  return v === "ok" || v === "private_profile" || v === "invalid_input" || v === "steam_error";
}

function parseCouplingResponse(json: unknown): CouplingResult {
  if (typeof json !== "object" || json === null) return empty("steam_error");
  if (!("data" in json) || typeof json.data !== "object" || json.data === null) return empty("steam_error");

  const { data } = json;
  if (!("matchedGames" in data) || !Array.isArray(data.matchedGames)) return empty("steam_error");
  if (!data.matchedGames.every(isCoupledGame)) return empty("steam_error");
  if (!hasNumberProp(data, "ownedCount") || !hasNumberProp(data, "matchedCount")) return empty("steam_error");

  const outcome =
    "meta" in json && typeof json.meta === "object" && json.meta !== null && "outcome" in json.meta && isOutcome(json.meta.outcome)
      ? json.meta.outcome
      : "ok";

  return {
    outcome,
    matchedGames: data.matchedGames,
    ownedCount: data.ownedCount,
    matchedCount: data.matchedCount,
  };
}
