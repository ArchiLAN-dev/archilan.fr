import { cache } from "react";
import { env } from "@/lib/env";

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
  if (!v || typeof v !== "object") return false;
  const s = v as Record<string, unknown>;
  return (
    typeof s.runsParticipated === "number" &&
    typeof s.goalCompletions === "number" &&
    typeof s.goalCompletionRate === "number" &&
    typeof s.totalChecksDone === "number" &&
    typeof s.totalItemsReceived === "number"
  );
}

function isPlayerProfilePayload(payload: unknown): payload is { data: PlayerProfile } {
  if (!payload || typeof payload !== "object") return false;
  const p = payload as Record<string, unknown>;
  const data = p.data;
  if (!data || typeof data !== "object") return false;
  const d = data as Record<string, unknown>;
  return (
    typeof d.slug === "string" &&
    (d.displayName === null || typeof d.displayName === "string") &&
    typeof d.joinedAt === "string" &&
    isPlayerStats(d.stats)
  );
}

function isRunHistoryEntry(v: unknown): v is RunHistoryEntry {
  if (!v || typeof v !== "object") return false;
  const e = v as Record<string, unknown>;
  return (
    typeof e.sessionId === "string" &&
    typeof e.eventName === "string" &&
    (e.finishedAt === null || typeof e.finishedAt === "string") &&
    typeof e.game === "string" &&
    typeof e.checksDone === "number" &&
    typeof e.itemsReceived === "number" &&
    (e.goalReachedAt === null || typeof e.goalReachedAt === "string") &&
    typeof e.wasReleased === "boolean" &&
    typeof e.isInvalidated === "boolean"
  );
}

function isPlayerHistoryPayload(payload: unknown): payload is PlayerHistory {
  if (!payload || typeof payload !== "object") return false;
  const p = payload as Record<string, unknown>;
  return Array.isArray(p.data) && (p.data as unknown[]).every(isRunHistoryEntry);
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
