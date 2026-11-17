import type { ConnectionSlot } from "@/components/slot-name-field";
import { slotTracker } from "@/features/tracker/slot-ref";

import type { CurrentWeeklyRun, WeeklyRunMyEntry } from "./weekly-runs-api";

/** The one slot of a weekly run, with its tracker when the run's game has a page (story 54.7). */
export function weeklyConnectionSlots(
  run: Pick<CurrentWeeklyRun, "weeklyRunId" | "gameId" | "gameSlug">,
  entry: Pick<WeeklyRunMyEntry, "slotName">,
): ConnectionSlot[] {
  if (!entry.slotName) return [];
  return [
    {
      name: entry.slotName,
      game: null,
      tracker: slotTracker(run.gameId, run.gameSlug, { kind: "weekly", weeklyRunId: run.weeklyRunId }),
    },
  ];
}
