import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/** Story 42.1: the periods of the admin statistics page, as the address and the API name them. */
export const STATS_PERIODS = [
  { code: "4s", label: "4 semaines" },
  { code: "12s", label: "12 semaines" },
  { code: "12m", label: "12 mois" },
] as const;

export type StatsPeriodCode = (typeof STATS_PERIODS)[number]["code"];

export const DEFAULT_STATS_PERIOD: StatsPeriodCode = "12s";

/** An unknown or missing value falls back to 12 weeks, as the API does. */
export function parseStatsPeriod(value: string | null): StatsPeriodCode {
  return STATS_PERIODS.find((period) => period.code === value)?.code ?? DEFAULT_STATS_PERIOD;
}

export type StatsBucket = { start: string; value: number; current: boolean };

/** One measure over the period: per bucket, the total, and the total of the period before. */
export type Trend = { series: StatsBucket[]; total: number; previous: number };

export type StatsPeriodInfo = { code: string; granularity: "week" | "month"; start: string; end: string; previousStart: string };

export type CommunityStats = {
  period: StatsPeriodInfo;
  accounts: number;
  members: number;
  accountsCreated: Trend;
  membershipsStarted: Trend;
  activePlayers: Trend;
  friendshipsAccepted: Trend;
  achievementsUnlocked: Trend;
};

export type TopGame = { gameId: string; name: string; players: number; checks: number };

export type SessionStats = {
  period: StatsPeriodInfo;
  runningSessions: number;
  activeRuns: number;
  runsCreated: Trend;
  runsLaunched: Trend;
  eventSessionsLaunched: Trend;
  weeklyLaunched: Trend;
  weeklyCompleted: Trend;
  goalsReached: Trend;
  topGames: TopGame[];
};

export type PelleStats = {
  period: StatsPeriodInfo;
  goldInCirculation: number;
  created: Trend;
  destroyed: Trend;
  byReason: { reason: string; created: number; destroyed: number }[];
};

function isBucket(v: unknown): v is StatsBucket {
  return typeof v === "object" && v !== null && hasStringProp(v, "start") && hasNumberProp(v, "value") && hasBooleanProp(v, "current");
}

export function isTrend(v: unknown): v is Trend {
  return (
    typeof v === "object" &&
    v !== null &&
    hasNumberProp(v, "total") &&
    hasNumberProp(v, "previous") &&
    "series" in v &&
    Array.isArray(v.series) &&
    v.series.every(isBucket)
  );
}

function isPeriodInfo(v: unknown): v is StatsPeriodInfo {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "code") &&
    hasStringProp(v, "granularity") &&
    (v.granularity === "week" || v.granularity === "month") &&
    hasStringProp(v, "start") &&
    hasStringProp(v, "end") &&
    hasStringProp(v, "previousStart")
  );
}

export function isCommunityStats(v: unknown): v is CommunityStats {
  return (
    typeof v === "object" &&
    v !== null &&
    "period" in v &&
    isPeriodInfo(v.period) &&
    hasNumberProp(v, "accounts") &&
    hasNumberProp(v, "members") &&
    "accountsCreated" in v &&
    isTrend(v.accountsCreated) &&
    "membershipsStarted" in v &&
    isTrend(v.membershipsStarted) &&
    "activePlayers" in v &&
    isTrend(v.activePlayers) &&
    "friendshipsAccepted" in v &&
    isTrend(v.friendshipsAccepted) &&
    "achievementsUnlocked" in v &&
    isTrend(v.achievementsUnlocked)
  );
}

function isTopGame(v: unknown): v is TopGame {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "gameId") &&
    hasStringProp(v, "name") &&
    hasNumberProp(v, "players") &&
    hasNumberProp(v, "checks")
  );
}

export function isSessionStats(v: unknown): v is SessionStats {
  return (
    typeof v === "object" &&
    v !== null &&
    "period" in v &&
    isPeriodInfo(v.period) &&
    hasNumberProp(v, "runningSessions") &&
    hasNumberProp(v, "activeRuns") &&
    "runsCreated" in v &&
    isTrend(v.runsCreated) &&
    "runsLaunched" in v &&
    isTrend(v.runsLaunched) &&
    "eventSessionsLaunched" in v &&
    isTrend(v.eventSessionsLaunched) &&
    "weeklyLaunched" in v &&
    isTrend(v.weeklyLaunched) &&
    "weeklyCompleted" in v &&
    isTrend(v.weeklyCompleted) &&
    "goalsReached" in v &&
    isTrend(v.goalsReached) &&
    "topGames" in v &&
    Array.isArray(v.topGames) &&
    v.topGames.every(isTopGame)
  );
}

export function isPelleStats(v: unknown): v is PelleStats {
  return (
    typeof v === "object" &&
    v !== null &&
    "period" in v &&
    isPeriodInfo(v.period) &&
    hasNumberProp(v, "goldInCirculation") &&
    "created" in v &&
    isTrend(v.created) &&
    "destroyed" in v &&
    isTrend(v.destroyed) &&
    "byReason" in v &&
    Array.isArray(v.byReason) &&
    v.byReason.every(
      (r: unknown) =>
        typeof r === "object" && r !== null && hasStringProp(r, "reason") && hasNumberProp(r, "created") && hasNumberProp(r, "destroyed"),
    )
  );
}

async function fetchSection<T>(section: string, period: StatsPeriodCode, guard: (v: unknown) => v is T): Promise<T | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/stats/${section}?period=${period}`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return guard(payload) ? payload : null;
  } catch {
    return null;
  }
}

export function fetchCommunityStats(period: StatsPeriodCode): Promise<CommunityStats | null> {
  return fetchSection("community", period, isCommunityStats);
}

export function fetchSessionStats(period: StatsPeriodCode): Promise<SessionStats | null> {
  return fetchSection("sessions", period, isSessionStats);
}

export function fetchPelleStats(period: StatsPeriodCode): Promise<PelleStats | null> {
  return fetchSection("pelles", period, isPelleStats);
}
