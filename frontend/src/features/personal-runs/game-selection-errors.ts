/**
 * Story 38.16: why a game selection was refused, game by game. The API answers « Sélection invalide. » with one
 * reason per refused game under `details["gameIds.<index>"]` (a disabled game, an apworld whose generation test
 * failed); the page says which game and why instead of the bare headline.
 */
export function selectionErrorMessage(body: unknown, gameIds: string[], gameName: (gameId: string) => string): string {
  const fallback = "Impossible de sauvegarder la sélection.";
  const error: unknown = typeof body === "object" && body !== null && "error" in body ? body.error : null;
  if (typeof error !== "object" || error === null) return fallback;

  const headline = "message" in error && typeof error.message === "string" ? error.message : fallback;
  const details: unknown = "details" in error ? error.details : null;
  if (typeof details !== "object" || details === null) return headline;

  const reasons: string[] = [];
  for (const [key, value] of Object.entries(details)) {
    const match = /^gameIds\.(\d+)$/.exec(key);
    const first: unknown = Array.isArray(value) ? value[0] : null;
    if (typeof first !== "string") continue;
    const gameId = match !== null ? gameIds[Number(match[1])] : undefined;
    reasons.push(gameId !== undefined ? `${gameName(gameId)} : ${first}` : first);
  }

  return reasons.length > 0 ? reasons.join(" ") : headline;
}
