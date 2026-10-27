import { cache } from "react";

import { env } from "@/lib/env";
import { hasNumberProp, hasStringProp } from "@/lib/type-guards";

export type StatFact = { key: string; label: string; value: number; unit: "seconds" | null };

export type StatGroup = { key: string; label: string; facts: StatFact[] };

export type StatSuperlative = { key: string; label: string; value: number };

export type GameStatRow = {
  gameId: string;
  name: string;
  slug: string | null;
  runs: number;
  goals: number;
  checks: number;
  playedSeconds: number;
  deaths: number;
};

export type PlayerStatsPage = {
  slug: string;
  displayName: string | null;
  /** When the hourly achievement recompute last kept the facts; null for a member never recomputed. */
  computedAt: string | null;
  groups: StatGroup[];
  superlatives: StatSuperlative[];
  games: GameStatRow[];
};

function isStatFact(v: unknown): v is StatFact {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "key") &&
    hasStringProp(v, "label") &&
    hasNumberProp(v, "value") &&
    "unit" in v &&
    (v.unit === null || v.unit === "seconds")
  );
}

function isStatGroup(v: unknown): v is StatGroup {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "key") &&
    hasStringProp(v, "label") &&
    "facts" in v &&
    Array.isArray(v.facts) &&
    v.facts.every(isStatFact)
  );
}

function isStatSuperlative(v: unknown): v is StatSuperlative {
  return typeof v === "object" && v !== null && hasStringProp(v, "key") && hasStringProp(v, "label") && hasNumberProp(v, "value");
}

function isGameStatRow(v: unknown): v is GameStatRow {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "gameId") &&
    hasStringProp(v, "name") &&
    "slug" in v &&
    (v.slug === null || typeof v.slug === "string") &&
    hasNumberProp(v, "runs") &&
    hasNumberProp(v, "goals") &&
    hasNumberProp(v, "checks") &&
    hasNumberProp(v, "playedSeconds") &&
    hasNumberProp(v, "deaths")
  );
}

export function isPlayerStatsPage(v: unknown): v is PlayerStatsPage {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "slug") &&
    "displayName" in v &&
    (v.displayName === null || typeof v.displayName === "string") &&
    "computedAt" in v &&
    (v.computedAt === null || typeof v.computedAt === "string") &&
    "groups" in v &&
    Array.isArray(v.groups) &&
    v.groups.every(isStatGroup) &&
    "superlatives" in v &&
    Array.isArray(v.superlatives) &&
    v.superlatives.every(isStatSuperlative) &&
    "games" in v &&
    Array.isArray(v.games) &&
    v.games.every(isGameStatRow)
  );
}

/** The public stats page of a member (story 30.55), or null when the player does not exist or the API fails. */
export const getPlayerStats = cache(async (slug: string): Promise<PlayerStatsPage | null> => {
  try {
    const response = await fetch(`${env.apiBaseUrl}/community/profiles/${encodeURIComponent(slug)}/stats`, {
      cache: "no-store",
    });
    if (!response.ok) {
      return null;
    }

    const payload: unknown = await response.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload) || !isPlayerStatsPage(payload.data)) {
      return null;
    }

    return payload.data;
  } catch {
    return null;
  }
});
