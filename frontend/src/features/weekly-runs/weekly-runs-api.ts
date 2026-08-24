import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { parsePatchFiles, type PatchFile } from "@/lib/patch-files";

// ── Types ──────────────────────────────────────────────────────────────────────

export type WeeklyRunLeaderboardEntry = {
  entryId: string;
  userId: string;
  displayName: string | null;
  attemptNumber: number;
  goalReachedAt: string | null;
  completionTimeSeconds: number | null;
  checksTotal: number | null;
  itemsTotal: number | null;
};

export type WeeklyRunParticipant = {
  entryId: string;
  userId: string;
  displayName: string | null;
  attemptNumber: number;
  goalReachedAt: string | null;
  connectionInfo: { host: string; port: number; uri?: string | null; password: string | null } | null;
};

export type WeeklyRunMyEntry = {
  entryId: string;
  externalSessionId: string | null;
  launchedAt: string | null;
  goalReachedAt: string | null;
  // Live status of the entry's AP container, from the shared Session lifecycle (story 17.13).
  // null = never launched. "running" = up; idle/stopped/crashed = relaunchable; restarting = in progress.
  sessionStatus: string | null;
  connectionInfo: { host: string; port: number; uri?: string | null; password: string | null } | null;
};

export type CurrentWeeklyRun = {
  weeklyRunId: string;
  isGenerated: boolean;
  templateName: string | null;
  yamlConfig: string | null;
  gameName: string;
  coverImageUrl: string | null;
  weekNumber: number;
  weekYear: number;
  status: "active" | "finished";
  startedAt: string | null;
  finishedAt: string | null;
  leaderboard: {
    fastest: WeeklyRunLeaderboardEntry[];
    fewestChecks: WeeklyRunLeaderboardEntry[];
    fewestItems: WeeklyRunLeaderboardEntry[];
  };
  participants: WeeklyRunParticipant[];
  myEntry: WeeklyRunMyEntry | null;
};

export type LaunchResult = {
  entryId: string;
  externalSessionId: string;
  connectionInfo: { host: string; port: number; uri?: string | null; password: string | null };
};

// ── Fetch functions ────────────────────────────────────────────────────────────

export async function fetchCurrentWeeklyRuns(): Promise<CurrentWeeklyRun[]> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/weekly-runs/current`);
    if (!res.ok) return [];
    const payload: unknown = await res.json();
    if (!isCurrentRunsPayload(payload)) return [];
    return payload.data;
  } catch {
    return [];
  }
}

export async function optInToWeeklyRun(
  weeklyRunId: string,
): Promise<{ entryId: string; userId: string; weeklyRunId: string } | { error: string } | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/weekly-runs/${weeklyRunId}/entries`, {
      method: "POST",
    });
    const payload: unknown = await res.json().catch(() => null);
    if (res.status === 422) {
      if (isErrorPayload(payload)) return { error: payload.error };
      return { error: "unknown" };
    }
    if (!res.ok) return null;
    return isOptInPayload(payload)
      ? { entryId: payload.data.id, userId: payload.data.userId, weeklyRunId: payload.data.weeklyRunId }
      : null;
  } catch {
    return null;
  }
}

export async function fetchWeeklyEntryPatches(
  weeklyRunId: string,
  entryId: string,
): Promise<PatchFile[]> {
  try {
    const res = await apiFetch(
      `${env.apiBaseUrl}/weekly-runs/${weeklyRunId}/entries/${entryId}/patches`,
    );
    if (!res.ok) return [];
    return parsePatchFiles(await res.json());
  } catch {
    return [];
  }
}

export type WeeklyEntryPlayerSlot = { index: string; name: string };

/**
 * Player slots of a launched entry - spectator/group/Bridge slots excluded, sorted by
 * slot index. Returns null on network error, non-OK response or invalid payload.
 */
export async function fetchWeeklyEntryPlayerSlots(
  weeklyRunId: string,
  entryId: string,
): Promise<WeeklyEntryPlayerSlot[] | null> {
  try {
    const res = await apiFetch(
      `${env.apiBaseUrl}/weekly-runs/${weeklyRunId}/entries/${entryId}/players`,
    );
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (!isEntryPlayersPayload(payload)) return null;
    return Object.entries(payload.data.slots)
      .filter(([, s]) => s.type !== "spectator" && s.type !== "group" && s.slot_name !== "Bridge")
      .map(([index, s]) => ({ index, name: s.slot_name }))
      .sort((a, b) => Number(a.index) - Number(b.index));
  } catch {
    return null;
  }
}

export async function withdrawFromWeeklyRun(
  weeklyRunId: string,
  entryId: string,
): Promise<boolean> {
  try {
    const res = await apiFetch(
      `${env.apiBaseUrl}/weekly-runs/${weeklyRunId}/entries/${entryId}`,
      { method: "DELETE" },
    );
    return res.status === 204;
  } catch {
    return false;
  }
}

export async function launchWeeklyEntry(
  weeklyRunId: string,
  entryId: string,
): Promise<LaunchResult | { error: string } | null> {
  try {
    const res = await apiFetch(
      `${env.apiBaseUrl}/weekly-runs/${weeklyRunId}/entries/${entryId}/launch`,
      { method: "POST" },
    );
    const payload: unknown = await res.json().catch(() => null);
    if (!res.ok) {
      if (isErrorPayload(payload)) return { error: payload.error };
      return null;
    }
    return isLaunchPayload(payload) ? payload.data : null;
  } catch {
    return null;
  }
}

/**
 * Relaunch an idle/stopped/crashed weekly entry's container (story 17.13). Reuses the shared
 * session restart endpoint - the orchestrateur session id equals the entry's external session id.
 * Returns true on 202 (relaunch initiated).
 */
export async function relaunchWeeklyEntry(externalSessionId: string): Promise<boolean> {
  try {
    const res = await apiFetch(
      `${env.apiBaseUrl}/sessions/${externalSessionId}/restart`,
      { method: "POST" },
    );
    return res.status === 202;
  } catch {
    return false;
  }
}

// ── Type guards ────────────────────────────────────────────────────────────────

function isCurrentRunsPayload(v: unknown): v is { data: CurrentWeeklyRun[] } {
  return typeof v === "object" && v !== null && "data" in v && Array.isArray(v.data);
}

function isErrorPayload(v: unknown): v is { error: string } {
  if (typeof v !== "object" || v === null) return false;
  if (!("error" in v) || typeof v.error !== "string") return false;
  return true;
}

function isOptInPayload(
  v: unknown,
): v is { data: { id: string; userId: string; weeklyRunId: string } } {
  if (typeof v !== "object" || v === null) return false;
  if (!("data" in v) || typeof v.data !== "object" || v.data === null) return false;
  const d: unknown = v.data;
  if (typeof d !== "object" || d === null) return false;
  return "id" in d && typeof d.id === "string";
}

function isLaunchPayload(v: unknown): v is { data: LaunchResult } {
  if (typeof v !== "object" || v === null) return false;
  if (!("data" in v) || typeof v.data !== "object" || v.data === null) return false;
  const d: unknown = v.data;
  if (typeof d !== "object" || d === null) return false;
  if (!("entryId" in d) || typeof d.entryId !== "string") return false;
  if (!("connectionInfo" in d) || typeof d.connectionInfo !== "object" || d.connectionInfo === null) return false;
  return true;
}

type RawEntryPlayerSlot = { slot_name: string; type?: string };

function isRawEntryPlayerSlot(v: unknown): v is RawEntryPlayerSlot {
  if (typeof v !== "object" || v === null) return false;
  if (!("slot_name" in v) || typeof v.slot_name !== "string") return false;
  if ("type" in v && v.type !== undefined && typeof v.type !== "string") return false;
  return true;
}

function isEntryPlayersPayload(v: unknown): v is { data: { slots: Record<string, RawEntryPlayerSlot> } } {
  if (typeof v !== "object" || v === null || !("data" in v)) return false;
  const data: unknown = Reflect.get(v, "data");
  if (typeof data !== "object" || data === null || !("slots" in data)) return false;
  const slots: unknown = Reflect.get(data, "slots");
  if (typeof slots !== "object" || slots === null) return false;
  return Object.values(slots).every((s: unknown) => isRawEntryPlayerSlot(s));
}

// ── Mercure goal event type guard ──────────────────────────────────────────────

export type GoalReachedEvent = {
  entryId: string;
};

export function isGoalReachedEvent(v: unknown): v is GoalReachedEvent {
  if (typeof v !== "object" || v === null) return false;
  if (!("event" in v) || v.event !== "goal_reached") return false;
  if (!("entryId" in v) || typeof v.entryId !== "string") return false;
  return true;
}
