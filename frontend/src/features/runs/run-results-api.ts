import { cache } from "react";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

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
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "slotId")) return false;
  if (!hasStringProp(v, "playerName")) return false;
  if (!hasStringProp(v, "game")) return false;
  if (!hasNumberProp(v, "checksDone")) return false;
  if (!hasNumberProp(v, "itemsReceived")) return false;
  if (!("goalReachedAt" in v) || (v.goalReachedAt !== null && typeof v.goalReachedAt !== "string")) return false;
  if (!("completionSeconds" in v) || (v.completionSeconds !== null && typeof v.completionSeconds !== "number")) return false;
  if (!hasBooleanProp(v, "wasReleased")) return false;
  return hasBooleanProp(v, "isInvalidated");
}

function isRunResultsPayload(payload: unknown): payload is { data: RunResults } {
  if (typeof payload !== "object" || payload === null) return false;
  if (!("data" in payload) || typeof payload.data !== "object" || payload.data === null) return false;
  const data = payload.data;
  if (!hasStringProp(data, "sessionId")) return false;
  if (!hasStringProp(data, "eventName")) return false;
  if (!("startedAt" in data) || (data.startedAt !== null && typeof data.startedAt !== "string")) return false;
  if (!("finishedAt" in data) || (data.finishedAt !== null && typeof data.finishedAt !== "string")) return false;
  if (!("durationSeconds" in data) || (data.durationSeconds !== null && typeof data.durationSeconds !== "number")) return false;
  if (!("slots" in data) || !Array.isArray(data.slots)) return false;
  return data.slots.every(isSlotResult);
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
