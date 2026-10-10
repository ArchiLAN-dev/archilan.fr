import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasNullableStringProp, hasStringProp } from "@/lib/type-guards";
import type { FriendCard } from "@/features/community/community-friends-api";
import type { RunOpenness } from "./types";

/** Story 43.14: a draft run a friend opened to the member, with a seat left. */
export type FriendsOpenRun = {
  runId: string;
  title: string;
  seatsWanted: number | null;
  joined: number;
  createdAt: string;
  owner: FriendCard;
};

export const FRIENDS_OPEN_RUNS_KEY = ["friends-open-runs"] as const;

export const MAX_SEATS_WANTED = 30;

function isCard(v: unknown): v is FriendCard {
  return typeof v === "object" && v !== null && hasStringProp(v, "userId") && hasStringProp(v, "slug") && hasNullableStringProp(v, "displayName") && hasNullableStringProp(v, "avatarUrl");
}

function isFriendsOpenRun(v: unknown): v is FriendsOpenRun {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "runId") || !hasStringProp(v, "title") || !hasStringProp(v, "createdAt")) return false;
  if (!("joined" in v) || typeof v.joined !== "number") return false;
  if (!("seatsWanted" in v) || (v.seatsWanted !== null && typeof v.seatsWanted !== "number")) return false;
  return "owner" in v && isCard(v.owner);
}

function dataOf(json: unknown): unknown {
  return typeof json === "object" && json !== null && "data" in json ? json.data : null;
}

function errorMessageOf(json: unknown): string | null {
  if (typeof json !== "object" || json === null || !("error" in json)) return null;
  const error = json.error;
  return typeof error === "object" && error !== null && hasStringProp(error, "message") ? error.message : null;
}

/** The runs the member's friends opened to them. Null on failure. */
export async function fetchFriendsOpenRuns(): Promise<FriendsOpenRun[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/account/friends-open-runs`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return Array.isArray(data) && data.every(isFriendsOpenRun) ? data : null;
  } catch {
    return null;
  }
}

export type JoinOpenRunResult = { ok: true } | { ok: false; message: string };

export async function joinOpenRun(runId: string): Promise<JoinOpenRunResult> {
  const failed = { ok: false as const, message: "Impossible de rejoindre la partie." };
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/runs/${encodeURIComponent(runId)}/join-open`, { method: "POST" });
    if (res.ok) return { ok: true };
    return { ok: false, message: errorMessageOf(await res.json()) ?? failed.message };
  } catch {
    return failed;
  }
}

export type SetOpennessResult = { ok: true } | { ok: false; message: string };

export async function setRunOpenness(runId: string, openness: RunOpenness, seatsWanted: number | null): Promise<SetOpennessResult> {
  const failed = { ok: false as const, message: "Réglage non enregistré, réessaie." };
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/runs/${encodeURIComponent(runId)}/openness`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ openness, seatsWanted }),
    });
    if (res.ok) return { ok: true };
    return { ok: false, message: errorMessageOf(await res.json()) ?? failed.message };
  } catch {
    return failed;
  }
}
