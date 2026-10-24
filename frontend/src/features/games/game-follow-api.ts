import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasStringProp } from "@/lib/type-guards";

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

/** Shared by the follow button and the list, so a change on one side refreshes the other (story 38.23). */
export const FOLLOWED_GAMES_KEY = ["followed-games"] as const;

export const gameFollowKey = (gameId: string) => ["game-follow", gameId] as const;

export type FollowedGame = { gameId: string; gameName: string; gameSlug: string };

function isFollowedGame(v: unknown): v is FollowedGame {
  if (typeof v !== "object" || v === null) return false;
  return hasStringProp(v, "gameId") && hasStringProp(v, "gameName") && hasStringProp(v, "gameSlug");
}

export function isFollowedGamesPayload(v: unknown): v is { data: FollowedGame[] } {
  if (typeof v !== "object" || v === null || !("data" in v)) return false;
  return Array.isArray(v.data) && v.data.every(isFollowedGame);
}

/** The games the member follows, by name (story 38.23). Null when it could not be read. */
export async function fetchFollowedGames(): Promise<FollowedGame[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/me/followed-games`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isFollowedGamesPayload(payload) ? payload.data : null;
  } catch {
    return null;
  }
}
