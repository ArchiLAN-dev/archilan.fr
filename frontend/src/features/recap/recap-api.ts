import { cache } from "react";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

export type RecapPodiumSlot = {
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

export type RecapNode = {
  slotId: string;
  slotName: string;
  game: string;
};

export type RecapEdge = {
  fromSlotId: string;
  toSlotId: string;
  count: number;
};

export type RecapLocalItem = {
  slotId: string;
  count: number;
};

export type RecapSuperlative = {
  key: string;
  label: string;
  slotId: string;
  value: number | string;
};

export type SessionRecap = {
  sessionId: string;
  eventName: string;
  startedAt: string | null;
  finishedAt: string | null;
  durationSeconds: number | null;
  vodUrl: string | null;
  generatedAt: string;
  podium: RecapPodiumSlot[];
  graph: {
    nodes: RecapNode[];
    edges: RecapEdge[];
    localItems: RecapLocalItem[];
  };
  superlatives: RecapSuperlative[];
};

function isPodiumSlot(v: unknown): v is RecapPodiumSlot {
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

function isNode(v: unknown): v is RecapNode {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "slotId") &&
    hasStringProp(v, "slotName") &&
    hasStringProp(v, "game")
  );
}

function isEdge(v: unknown): v is RecapEdge {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "fromSlotId") &&
    hasStringProp(v, "toSlotId") &&
    hasNumberProp(v, "count")
  );
}

function isLocalItem(v: unknown): v is RecapLocalItem {
  return typeof v === "object" && v !== null && hasStringProp(v, "slotId") && hasNumberProp(v, "count");
}

function isSuperlative(v: unknown): v is RecapSuperlative {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "key")) return false;
  if (!hasStringProp(v, "label")) return false;
  if (!hasStringProp(v, "slotId")) return false;
  return "value" in v && (typeof v.value === "string" || typeof v.value === "number");
}

function isGraph(v: unknown): v is SessionRecap["graph"] {
  if (typeof v !== "object" || v === null) return false;
  if (!("nodes" in v) || !Array.isArray(v.nodes) || !v.nodes.every(isNode)) return false;
  if (!("edges" in v) || !Array.isArray(v.edges) || !v.edges.every(isEdge)) return false;
  return "localItems" in v && Array.isArray(v.localItems) && v.localItems.every(isLocalItem);
}

function isRecapPayload(payload: unknown): payload is { data: SessionRecap } {
  if (typeof payload !== "object" || payload === null) return false;
  if (!("data" in payload) || typeof payload.data !== "object" || payload.data === null) return false;
  const data = payload.data;
  if (!hasStringProp(data, "sessionId")) return false;
  if (!hasStringProp(data, "eventName")) return false;
  if (!("startedAt" in data) || (data.startedAt !== null && typeof data.startedAt !== "string")) return false;
  if (!("finishedAt" in data) || (data.finishedAt !== null && typeof data.finishedAt !== "string")) return false;
  if (!("durationSeconds" in data) || (data.durationSeconds !== null && typeof data.durationSeconds !== "number")) return false;
  if (!("vodUrl" in data) || (data.vodUrl !== null && typeof data.vodUrl !== "string")) return false;
  if (!hasStringProp(data, "generatedAt")) return false;
  if (!("podium" in data) || !Array.isArray(data.podium) || !data.podium.every(isPodiumSlot)) return false;
  if (!("graph" in data) || !isGraph(data.graph)) return false;
  return "superlatives" in data && Array.isArray(data.superlatives) && data.superlatives.every(isSuperlative);
}

export const getSessionRecap = cache(async (sessionId: string): Promise<SessionRecap | null> => {
  try {
    const response = await fetch(`${env.apiBaseUrl}/parties/${sessionId}/recap`, {
      cache: "no-store",
    });

    if (!response.ok) {
      return null;
    }

    const payload: unknown = await response.json();
    if (!isRecapPayload(payload)) {
      return null;
    }

    return payload.data;
  } catch {
    return null;
  }
});
