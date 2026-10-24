import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp } from "@/lib/type-guards";

/**
 * Following a game, to be told of its new apworlds (story 38.22). Every function returns a value
 * instead of throwing (AC-API2).
 */

function isFollowPayload(v: unknown): v is { data: { following: boolean } } {
  if (typeof v !== "object" || v === null || !("data" in v)) return false;
  return typeof v.data === "object" && v.data !== null && hasBooleanProp(v.data, "following");
}

/** Null when it could not be read (signed out, unknown game, network). */
export async function fetchGameFollow(gameId: string): Promise<boolean | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/games/${gameId}/follow`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isFollowPayload(payload) ? payload.data.following : null;
  } catch {
    return null;
  }
}

/** Follows or stops following. The state the API settled on, or null when the call did not go through. */
export async function setGameFollow(gameId: string, follow: boolean): Promise<boolean | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/games/${gameId}/follow`, { method: follow ? "PUT" : "DELETE" });
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isFollowPayload(payload) ? payload.data.following : null;
  } catch {
    return null;
  }
}
