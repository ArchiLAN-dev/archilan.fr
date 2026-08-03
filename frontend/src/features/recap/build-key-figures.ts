import { isProgressionFind, type FeedEvent } from "./feed-api";
import type { SessionRecap } from "./recap-api";

/**
 * One headline number. `value` is pre-formatted for display; a figure that cannot be established
 * is simply absent from the list rather than shown as zero (story 32.15, AC3).
 */
export type KeyFigure = { key: string; label: string; value: string };

/**
 * The AP item classification only exists since story 32.9. Events persisted before that carry a
 * null `flags`, which means "unknown", not "filler" - so a run whose feed has no flag at all can
 * report no progression share, rather than an honest-looking 0 %.
 */
export function hasUsableFlags(feed: FeedEvent[]): boolean {
  return feed.some((event) => event.type === "item-received" && event.item.flags !== null);
}

export function buildRecapKeyFigures(recap: SessionRecap, feed: FeedEvent[]): KeyFigure[] {
  const items = feed.filter((event) => event.type === "item-received");
  const hints = feed.filter((event) => event.type === "hint").length;
  const checks = recap.podium.reduce((sum, slot) => sum + slot.checksDone, 0);

  const figures: KeyFigure[] = [];

  if (recap.durationSeconds !== null) {
    figures.push({ key: "duration", label: "Durée", value: formatCompactDuration(recap.durationSeconds) });
  }
  if (recap.podium.length > 0) {
    figures.push({ key: "players", label: "Joueurs", value: String(recap.podium.length) });
  }
  if (items.length > 0) {
    figures.push({ key: "items", label: "Objets échangés", value: String(items.length) });
  }
  if (items.length > 0 && hasUsableFlags(items)) {
    const progression = items.filter(isProgressionFind).length;
    figures.push({
      key: "progression",
      label: "Dont progression",
      value: `${Math.round((progression / items.length) * 100)} %`,
    });
  }
  if (checks > 0) {
    // "Complétés", not "trouvés": a check is done, not found - what you find is the item inside it.
    // Same wording as the community stats widget, which already says "checks complétés".
    figures.push({ key: "checks", label: "Checks complétés", value: String(checks) });
  }
  if (hints > 0) {
    figures.push({ key: "hints", label: "Indices demandés", value: String(hints) });
  }

  return figures;
}

/**
 * Compact form for a headline slot. Truncates minutes exactly like `formatDuration`, which renders
 * the same duration in the header just above: rounding here printed "3 h 11" fifteen pixels under a
 * header reading "3 h 10 min".
 */
function formatCompactDuration(seconds: number): string {
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  if (hours === 0) return `${minutes} min`;
  return minutes === 0 ? `${hours} h` : `${hours} h ${String(minutes).padStart(2, "0")}`;
}
