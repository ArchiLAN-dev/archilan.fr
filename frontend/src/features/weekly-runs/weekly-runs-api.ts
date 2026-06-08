import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

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
  connectionInfo: { host: string; port: number; password: string | null } | null;
};

export type WeeklyRunMyEntry = {
  entryId: string;
  externalSessionId: string | null;
  launchedAt: string | null;
  goalReachedAt: string | null;
  connectionInfo: { host: string; port: number; password: string | null } | null;
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
  connectionInfo: { host: string; port: number; password: string | null };
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
): Promise<string[]> {
  try {
    const res = await apiFetch(
      `${env.apiBaseUrl}/weekly-runs/${weeklyRunId}/entries/${entryId}/patches`,
    );
    if (!res.ok) return [];
    const payload: unknown = await res.json();
    if (!isWeeklyEntryPatchesPayload(payload)) return [];
    return payload.data.files;
  } catch {
    return [];
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

function isWeeklyEntryPatchesPayload(v: unknown): v is { data: { files: string[] } } {
  if (typeof v !== "object" || v === null || !("data" in v)) return false;
  const data: unknown = Reflect.get(v, "data");
  if (typeof data !== "object" || data === null || !("files" in data)) return false;
  const files: unknown = Reflect.get(data, "files");
  return Array.isArray(files) && files.every((f): f is string => typeof f === "string");
}

// ── Authenticated patch download ───────────────────────────────────────────────

export async function downloadPatch(
  weeklyRunId: string,
  entryId: string,
  filename: string,
): Promise<void> {
  try {
    const res = await apiFetch(
      `${env.apiBaseUrl}/weekly-runs/${weeklyRunId}/entries/${entryId}/patches/${encodeURIComponent(filename)}`,
    );
    if (!res.ok) return;
    const blob = await res.blob();
    const objectUrl = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = objectUrl;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(objectUrl);
  } catch {
    // non-critical — user will notice the file didn't arrive
  }
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
