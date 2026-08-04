import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

/** Which of the player's lists is being addressed - mirrors the API's `kind` path segment. */
export type GameListKind = "owned";

/**
 * The lists a player keeps on ArchiLAN (story 28.13) - deliberately independent of the Steam
 * coupling, which can only ever recognise titles carrying a `steamAppId`.
 *
 * Every function returns a value instead of throwing (AC-API2): not being signed in is a normal
 * state here, not an error to surface.
 */
export async function fetchGameListIds(kind: GameListKind): Promise<string[]> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/me/game-lists/${kind}`);
    if (!res.ok) return [];

    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return [];
    if (!Array.isArray(payload.data)) return [];

    return payload.data.filter((id): id is string => typeof id === "string");
  } catch {
    return [];
  }
}

/** Puts a game on a list, or takes it off. Returns false when the call did not go through. */
export async function setGameInList(
  kind: GameListKind,
  gameId: string,
  inList: boolean,
): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/me/game-lists/${kind}/${gameId}`, {
      method: inList ? "PUT" : "DELETE",
    });

    return res.ok;
  } catch {
    return false;
  }
}
