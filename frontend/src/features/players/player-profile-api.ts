import { cache } from "react";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

export type PlayerStats = {
  runsParticipated: number;
  goalCompletions: number;
  goalCompletionRate: number;
  totalChecksDone: number;
  totalItemsReceived: number;
};

export type PlayerProfile = {
  slug: string;
  displayName: string | null;
  joinedAt: string;
  stats: PlayerStats;
};

export type RunHistoryEntry = {
  sessionId: string;
  eventName: string;
  finishedAt: string | null;
  game: string;
  checksDone: number;
  itemsReceived: number;
  goalReachedAt: string | null;
  wasReleased: boolean;
  isInvalidated: boolean;
};

export type PlayerHistory = {
  data: RunHistoryEntry[];
  meta: { page: number; limit: number; total: number };
};

function isPlayerStats(v: unknown): v is PlayerStats {
  if (typeof v !== "object" || v === null) return false;
  return (
    hasNumberProp(v, "runsParticipated") &&
    hasNumberProp(v, "goalCompletions") &&
    hasNumberProp(v, "goalCompletionRate") &&
    hasNumberProp(v, "totalChecksDone") &&
    hasNumberProp(v, "totalItemsReceived")
  );
}

function isPlayerProfilePayload(payload: unknown): payload is { data: PlayerProfile } {
  if (typeof payload !== "object" || payload === null) return false;
  if (!("data" in payload) || typeof payload.data !== "object" || payload.data === null) return false;
  const data = payload.data;
  if (!hasStringProp(data, "slug")) return false;
  if (!("displayName" in data) || (data.displayName !== null && typeof data.displayName !== "string")) return false;
  if (!hasStringProp(data, "joinedAt")) return false;
  if (!("stats" in data)) return false;
  return isPlayerStats(data.stats);
}

function isRunHistoryEntry(v: unknown): v is RunHistoryEntry {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "sessionId")) return false;
  if (!hasStringProp(v, "eventName")) return false;
  if (!("finishedAt" in v) || (v.finishedAt !== null && typeof v.finishedAt !== "string")) return false;
  if (!hasStringProp(v, "game")) return false;
  if (!hasNumberProp(v, "checksDone")) return false;
  if (!hasNumberProp(v, "itemsReceived")) return false;
  if (!("goalReachedAt" in v) || (v.goalReachedAt !== null && typeof v.goalReachedAt !== "string")) return false;
  if (!hasBooleanProp(v, "wasReleased")) return false;
  return hasBooleanProp(v, "isInvalidated");
}

function isPlayerHistoryPayload(payload: unknown): payload is PlayerHistory {
  if (typeof payload !== "object" || payload === null) return false;
  if (!("data" in payload) || !Array.isArray(payload.data)) return false;
  return payload.data.every(isRunHistoryEntry);
}

export const getPlayerProfile = cache(async (slug: string): Promise<PlayerProfile | null> => {
  try {
    const response = await fetch(`${env.apiBaseUrl}/players/${encodeURIComponent(slug)}`, {
      cache: "no-store",
    });

    if (!response.ok) {
      return null;
    }

    const payload: unknown = await response.json();
    if (!isPlayerProfilePayload(payload)) {
      return null;
    }

    return payload.data;
  } catch {
    return null;
  }
});

export const getPlayerHistory = cache(async (slug: string): Promise<PlayerHistory | null> => {
  try {
    const response = await fetch(
      `${env.apiBaseUrl}/players/${encodeURIComponent(slug)}/history?limit=100`,
      { cache: "no-store" },
    );

    if (!response.ok) {
      return null;
    }

    const payload: unknown = await response.json();
    if (!isPlayerHistoryPayload(payload)) {
      return null;
    }

    return payload;
  } catch {
    return null;
  }
});
