/** Story 43.7: where a member playing stands in the slot their presence shows. */
export type SlotState = "playing" | "bk" | "goal" | "unknown";

export type RichPresence = { game: string | null; slotState?: string | null; progressPercent?: number | null };

export type PresenceTone = "playing" | "bk" | "goal";

/** The pill's colours, those of the progress grid's BK and goal (`PlayerProgressGrid`). */
export const PRESENCE_TONE_CLASSES: Record<PresenceTone, { pill: string; dot: string }> = {
  playing: { pill: "border-emerald-500/40 bg-emerald-500/10 text-emerald-300", dot: "bg-emerald-400 animate-pulse" },
  bk: { pill: "border-danger/40 bg-danger/10 text-danger", dot: "bg-danger" },
  goal: { pill: "border-success/50 bg-success/10 text-success", dot: "bg-success" },
};

export function presenceTone(presence: { slotState?: string | null }): PresenceTone {
  if (presence.slotState === "bk") return "bk";
  if (presence.slotState === "goal") return "goal";
  return "playing";
}

/** The state alone: « En jeu », « En BK », « Objectif atteint ». */
export function presenceStateLabel(presence: { slotState?: string | null }): string {
  const tone = presenceTone(presence);
  if (tone === "bk") return "En BK";
  if (tone === "goal") return "Objectif atteint";
  return "En jeu";
}

/**
 * « En jeu · Hollow Knight · 42 % », « En BK · Hollow Knight », « Objectif atteint · Hollow Knight ». The progress only
 * while playing; without a detailed tracking (unknown state), the game alone.
 */
export function presenceLabel(presence: RichPresence): string {
  const parts = [presenceStateLabel(presence)];
  if (presence.game) parts.push(presence.game);
  const percent = presence.progressPercent;
  if (presence.slotState === "playing" && typeof percent === "number") parts.push(`${percent} %`);
  return parts.join(" · ");
}
