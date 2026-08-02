// Shared pure formatting for the recap surfaces (page + OG share card). Keep free of any
// browser/server API so the OG image route and jest (node env) can both consume it.

/**
 * A superlative's value is either a count of items or an instant (the server decides per key), so
 * the formatting has to branch on what arrived rather than on the key.
 */
export function formatSuperlativeValue(value: number | string): string {
  if (typeof value === "number") return `${value} objets`;
  const date = new Date(value);
  if (!Number.isNaN(date.getTime())) {
    return date.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" });
  }
  return value;
}

export function formatDuration(totalSeconds: number): string {
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;
  if (hours > 0) return `${hours} h ${minutes.toString().padStart(2, "0")} min`;
  if (minutes > 0) return `${minutes} min ${seconds.toString().padStart(2, "0")} s`;
  return `${seconds} s`;
}
