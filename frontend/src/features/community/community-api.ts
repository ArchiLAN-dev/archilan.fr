import { env } from "@/lib/env";
import { hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

export type LeaderboardAxis = "goals" | "checks" | "speed";

export type LeaderboardEntry = {
  rank: number;
  slug: string;
  displayName: string;
  avatarUrl: string | null;
  value: number;
  unit: string;
};

export type LeaderboardResponse = {
  data: LeaderboardEntry[];
  meta: { axis: string; page: number; total: number };
};

export type CommunityStats = {
  totalFinishedSessions: number;
  totalChecksDone: number;
  totalGoalsReached: number;
};

function isLeaderboardEntry(v: unknown): v is LeaderboardEntry {
  if (typeof v !== "object" || v === null) return false;
  return (
    hasNumberProp(v, "rank") &&
    hasStringProp(v, "slug") &&
    hasStringProp(v, "displayName") &&
    hasNullableStringProp(v, "avatarUrl") &&
    hasNumberProp(v, "value") &&
    hasStringProp(v, "unit")
  );
}

function isLeaderboardResponse(payload: unknown): payload is LeaderboardResponse {
  if (typeof payload !== "object" || payload === null) return false;
  if (!("data" in payload) || !Array.isArray(payload.data)) return false;
  if (!("meta" in payload) || typeof payload.meta !== "object" || payload.meta === null) return false;
  return (
    payload.data.every(isLeaderboardEntry) &&
    hasStringProp(payload.meta, "axis") &&
    hasNumberProp(payload.meta, "page") &&
    hasNumberProp(payload.meta, "total")
  );
}

function isCommunityStatsPayload(payload: unknown): payload is { data: CommunityStats } {
  if (typeof payload !== "object" || payload === null) return false;
  if (!("data" in payload) || typeof payload.data !== "object" || payload.data === null) return false;
  const data = payload.data;
  return (
    hasNumberProp(data, "totalFinishedSessions") &&
    hasNumberProp(data, "totalChecksDone") &&
    hasNumberProp(data, "totalGoalsReached")
  );
}

export async function fetchLeaderboard(
  axis: LeaderboardAxis,
  limit: number,
  eventId?: string,
): Promise<LeaderboardResponse | null> {
  try {
    const params = new URLSearchParams({ axis, limit: String(limit) });
    if (eventId) params.set("eventId", eventId);
    const response = await fetch(`${env.apiBaseUrl}/leaderboard?${params.toString()}`, {
      cache: "no-store",
    });
    if (!response.ok) return null;
    const payload: unknown = await response.json();
    if (!isLeaderboardResponse(payload)) return null;
    return payload;
  } catch {
    return null;
  }
}

export async function fetchCommunityStats(): Promise<CommunityStats | null> {
  try {
    const response = await fetch(`${env.apiBaseUrl}/community/stats`, {
      cache: "no-store",
    });
    if (!response.ok) return null;
    const payload: unknown = await response.json();
    if (!isCommunityStatsPayload(payload)) return null;
    return payload.data;
  } catch {
    return null;
  }
}

export function formatLeaderboardValue(value: number, axis: LeaderboardAxis): string {
  if (axis === "speed") {
    return formatDuration(value);
  }
  return formatNumber(value);
}

export function formatLeaderboardUnit(value: number, axis: LeaderboardAxis): string {
  if (axis === "goals") return value === 1 ? "objectif" : "objectifs";
  if (axis === "checks") return "checks";
  return "";
}

function formatDuration(seconds: number): string {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;
  if (h === 0 && m === 0) return `${s}s`;
  if (h === 0) return `${m}min`;
  if (m === 0) return `${h}h`;
  return `${h}h ${m}min`;
}

function formatNumber(value: number): string {
  return new Intl.NumberFormat("fr-FR").format(value);
}
