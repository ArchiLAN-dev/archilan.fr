/**
 * How long a run has been asleep, in a form that survives a long absence.
 *
 * The badge this replaces counted in hours and minutes only, so a run left alone for ten days
 * announced "Inactif depuis 243h 3min" - a number nobody reads as a duration. Past a day the hours
 * stop carrying meaning, so they are dropped rather than accumulated.
 *
 * Returns null when the timestamp cannot be read or lies in the future, so the caller simply omits
 * the duration instead of printing a negative one.
 */
export function formatIdleDuration(lastActivityAt: string, now: number): string | null {
  const since = new Date(lastActivityAt).getTime();
  if (Number.isNaN(since)) return null;

  const minutes = Math.floor((now - since) / 60_000);
  if (minutes < 0) return null;
  if (minutes < 60) return `${minutes} min`;

  const hours = Math.floor(minutes / 60);
  if (hours < 24) {
    const rest = minutes % 60;
    return rest > 0 ? `${hours} h ${rest} min` : `${hours} h`;
  }

  const days = Math.floor(hours / 24);
  return days > 1 ? `${days} jours` : "1 jour";
}
