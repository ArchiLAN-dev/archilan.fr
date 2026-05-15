import { env } from "@/lib/env";

export type LeaderboardAxis = "goals" | "checks" | "speed";

export type LeaderboardEntry = {
  rank: number;
  slug: string;
  displayName: string;
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
  if (!v || typeof v !== "object") return false;
  const e = v as Record<string, unknown>;
  return (
    typeof e.rank === "number" &&
    typeof e.slug === "string" &&
    typeof e.displayName === "string" &&
    typeof e.value === "number" &&
    typeof e.unit === "string"
  );
}

function isLeaderboardResponse(payload: unknown): payload is LeaderboardResponse {
  if (!payload || typeof payload !== "object") return false;
  const p = payload as Record<string, unknown>;
  return (
    Array.isArray(p.data) &&
    (p.data as unknown[]).every(isLeaderboardEntry) &&
    typeof p.meta === "object" &&
    p.meta !== null
  );
}

function isCommunityStatsPayload(
  payload: unknown,
): payload is { data: CommunityStats } {
  if (!payload || typeof payload !== "object") return false;
  const p = payload as Record<string, unknown>;
  const data = p.data;
  if (!data || typeof data !== "object") return false;
  const d = data as Record<string, unknown>;
  return (
    typeof d.totalFinishedSessions === "number" &&
    typeof d.totalChecksDone === "number" &&
    typeof d.totalGoalsReached === "number"
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
