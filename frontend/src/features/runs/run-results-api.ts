import { cache } from "react";
import { env } from "@/lib/env";

export type SlotResult = {
  slotId: string;
  playerName: string;
  game: string;
  checksDone: number;
  itemsReceived: number;
  goalReachedAt: string | null;
  completionSeconds: number | null;
  wasReleased: boolean;
  isInvalidated: boolean;
};

export type RunResults = {
  sessionId: string;
  eventName: string;
  startedAt: string | null;
  finishedAt: string | null;
  durationSeconds: number | null;
  slots: SlotResult[];
};

function isSlotResult(v: unknown): v is SlotResult {
  if (!v || typeof v !== "object") return false;
  const s = v as Record<string, unknown>;
  return (
    typeof s.slotId === "string" &&
    typeof s.playerName === "string" &&
    typeof s.game === "string" &&
    typeof s.checksDone === "number" &&
    typeof s.itemsReceived === "number" &&
    (s.goalReachedAt === null || typeof s.goalReachedAt === "string") &&
    (s.completionSeconds === null || typeof s.completionSeconds === "number") &&
    typeof s.wasReleased === "boolean" &&
    typeof s.isInvalidated === "boolean"
  );
}

function isRunResultsPayload(payload: unknown): payload is { data: RunResults } {
  if (!payload || typeof payload !== "object") return false;
  const p = payload as Record<string, unknown>;
  const data = p.data;
  if (!data || typeof data !== "object") return false;
  const d = data as Record<string, unknown>;
  return (
    typeof d.sessionId === "string" &&
    typeof d.eventName === "string" &&
    Array.isArray(d.slots) &&
    (d.slots as unknown[]).every(isSlotResult)
  );
}

export const getRunResults = cache(async (runId: string): Promise<RunResults | null> => {
  try {
    const response = await fetch(`${env.apiBaseUrl}/runs/${runId}/results`, {
      cache: "no-store",
    });

    if (!response.ok) {
      return null;
    }

    const payload: unknown = await response.json();
    if (!isRunResultsPayload(payload)) {
      return null;
    }

    return payload.data;
  } catch {
    return null;
  }
});
