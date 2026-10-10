import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableNumberProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/** Story 43.12: a player of the run, as the nudge button reads them. */
export type RunNudgePlayer = {
  userId: string;
  lastCheckAt: string | null;
  idle: boolean;
  muted: boolean;
  lastNudgedAt: string | null;
  // Whole hours since the last nudge while it still holds the daily cap, null otherwise.
  nudgedHoursAgo: number | null;
  canNudge: boolean;
};

/** `muted`: the caller turned nudges off for this run. */
export type RunNudges = { muted: boolean; players: RunNudgePlayer[] };

function isPlayer(v: unknown): v is RunNudgePlayer {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "userId") &&
    hasNullableStringProp(v, "lastCheckAt") &&
    hasBooleanProp(v, "idle") &&
    hasBooleanProp(v, "muted") &&
    hasNullableStringProp(v, "lastNudgedAt") &&
    hasNullableNumberProp(v, "nudgedHoursAgo") &&
    hasBooleanProp(v, "canNudge")
  );
}

function dataOf(json: unknown): unknown {
  return typeof json === "object" && json !== null && "data" in json ? json.data : null;
}


/** Who the caller may nudge in the run. Null on failure or when the caller is not in it. */
export async function fetchRunNudges(runId: string): Promise<RunNudges | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/runs/${encodeURIComponent(runId)}/nudges`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    if (typeof data !== "object" || data === null || !hasBooleanProp(data, "muted") || !("players" in data)) return null;
    const players = data.players;
    return Array.isArray(players) && players.every(isPlayer) ? { muted: data.muted, players } : null;
  } catch {
    return null;
  }
}

/** `hoursAgo` on a refusal: someone already nudged them that many hours ago. */
export type NudgeResult = { ok: true } | { ok: false; hoursAgo: number | null };

export async function nudgeRunPlayer(runId: string, userId: string): Promise<NudgeResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/runs/${encodeURIComponent(runId)}/nudges/${encodeURIComponent(userId)}`, { method: "POST" });
    if (res.ok) return { ok: true };
    const data = dataOf(await res.json());
    return { ok: false, hoursAgo: typeof data === "object" && data !== null && hasNumberProp(data, "hoursAgo") ? data.hoursAgo : null };
  } catch {
    return { ok: false, hoursAgo: null };
  }
}

/** Turns nudges off (or back on) for the caller in this run. */
export async function muteRunNudges(runId: string, muted: boolean): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/runs/${encodeURIComponent(runId)}/nudges/mute`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ muted }),
    });
    return res.ok;
  } catch {
    return false;
  }
}
