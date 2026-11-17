import type { ConnectionSlot } from "@/components/slot-name-field";
import { slotTracker } from "@/features/tracker/slot-ref";

import type { PersonalRun } from "./types";

/** The caller's slots in a private party, each with its tracker when its game has a page (story 54.7). */
export function runConnectionSlots(run: Pick<PersonalRun, "id" | "mySlots">): ConnectionSlot[] {
  return (run.mySlots ?? []).map((slot) => ({
    name: slot.name,
    game: slot.game,
    tracker: slotTracker(slot.gameId, slot.gameSlug, { kind: "run", runId: run.id, slotName: slot.name }),
  }));
}
