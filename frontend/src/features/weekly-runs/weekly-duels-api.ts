import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasStringProp } from "@/lib/type-guards";

/** Story 43.15: where a member of a duel stands in the weekly run. `invited` has not answered yet. */
export type DuelStandingStatus = "goal" | "launched" | "registered" | "none" | "invited";

export type DuelStanding = {
  userId: string;
  slug: string;
  displayName: string | null;
  avatarUrl: string | null;
  avatarAnimatedUrl?: string | null;
  avatarFrame?: string | null;
  status: DuelStandingStatus;
  completionTimeSeconds: number | null;
  isViewer: boolean;
  isCreator: boolean;
};

/** A duel between friends on a weekly run, open until the run ends. */
export type WeeklyDuel = {
  duelId: string;
  weeklyRunId: string;
  gameName: string | null;
  isCreator: boolean;
  myStatus: "pending" | "accepted";
  standings: DuelStanding[];
};

export const MAX_DUEL_OPPONENTS = 5;

export function weeklyDuelsKey(weeklyRunId: string | null): readonly unknown[] {
  return ["weekly-duels", weeklyRunId ?? "all"];
}

const STATUSES: DuelStandingStatus[] = ["goal", "launched", "registered", "none", "invited"];

function isDuelStanding(v: unknown): v is DuelStanding {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "userId") || !hasStringProp(v, "slug") || !hasNullableStringProp(v, "displayName") || !hasNullableStringProp(v, "avatarUrl")) return false;
  if (!("status" in v) || !STATUSES.some((s) => s === v.status)) return false;
  if (!("completionTimeSeconds" in v) || (v.completionTimeSeconds !== null && typeof v.completionTimeSeconds !== "number")) return false;
  return hasBooleanProp(v, "isViewer") && hasBooleanProp(v, "isCreator");
}

function isWeeklyDuel(v: unknown): v is WeeklyDuel {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "duelId") || !hasStringProp(v, "weeklyRunId") || !hasNullableStringProp(v, "gameName") || !hasBooleanProp(v, "isCreator")) return false;
  if (!("myStatus" in v) || (v.myStatus !== "pending" && v.myStatus !== "accepted")) return false;
  return "standings" in v && Array.isArray(v.standings) && v.standings.every(isDuelStanding);
}

function dataOf(json: unknown): unknown {
  return typeof json === "object" && json !== null && "data" in json ? json.data : null;
}

function errorMessageOf(json: unknown): string | null {
  if (typeof json !== "object" || json === null || !("error" in json)) return null;
  const error = json.error;
  return typeof error === "object" && error !== null && hasStringProp(error, "message") ? error.message : null;
}

/** The member's open duels, on one weekly run or on all. Null on failure. */
export async function fetchWeeklyDuels(weeklyRunId: string | null): Promise<WeeklyDuel[] | null> {
  const query = weeklyRunId === null ? "" : `?weeklyRun=${encodeURIComponent(weeklyRunId)}`;
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/weekly-duels${query}`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return Array.isArray(data) && data.every(isWeeklyDuel) ? data : null;
  } catch {
    return null;
  }
}

export type DuelWriteResult = { ok: true } | { ok: false; message: string };

async function post(path: string, body: unknown, fallback: string): Promise<DuelWriteResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}${path}`, {
      method: "POST",
      ...(body === undefined ? {} : { headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) }),
    });
    if (res.ok) return { ok: true };
    return { ok: false, message: errorMessageOf(await res.json()) ?? fallback };
  } catch {
    return { ok: false, message: fallback };
  }
}

export function challengeFriends(weeklyRunId: string, userIds: string[]): Promise<DuelWriteResult> {
  return post(`/weekly-runs/${encodeURIComponent(weeklyRunId)}/duels`, { userIds }, "Impossible de lancer le duel.");
}

export function answerWeeklyDuel(duelId: string, answer: "accept" | "decline"): Promise<DuelWriteResult> {
  return post(`/weekly-duels/${encodeURIComponent(duelId)}/${answer}`, undefined, "Impossible de répondre au duel.");
}
