/** Story 41.64: « 6 h », « 2 h 30 », « 45 min » - time played is counted in minutes and read in hours. */
export function formatHours(minutes: number): string {
  const total = Math.max(0, Math.floor(minutes));
  const hours = Math.floor(total / 60);
  const rest = total % 60;
  if (hours === 0) return `${rest} min`;
  return rest === 0 ? `${hours} h` : `${hours} h ${String(rest).padStart(2, "0")}`;
}

/** An amount of a quest metric: a plain number, or hours for time played. */
export function formatQuestAmount(amount: number, duration: boolean): string {
  return duration ? formatHours(amount) : String(amount);
}
