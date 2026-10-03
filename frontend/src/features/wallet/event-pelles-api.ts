import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/** Story 41.2: the pelles of one event, admin side. */
export type EventPelleParticipant = { userId: string; displayName: string; balance: number; banned: boolean };

export type EventPelles = {
  eventId: string;
  eventTitle: string;
  endsAt: string;
  ended: boolean;
  distributed: number;
  inCirculation: number;
  converted: number;
  destroyed: number;
  participants: EventPelleParticipant[];
};

export type DistributeInput = {
  amount: number;
  label: string;
  requestId: string;
  /** Null hands the pelles to every active registrant. */
  userIds: string[] | null;
};

export type DistributeResult =
  | { kind: "ok"; credited: number; skipped: number; alreadyCredited: number }
  | { kind: "error"; message: string };

function isParticipant(v: unknown): v is EventPelleParticipant {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "userId") &&
    hasStringProp(v, "displayName") &&
    hasNumberProp(v, "balance") &&
    hasBooleanProp(v, "banned")
  );
}

export function isEventPelles(v: unknown): v is EventPelles {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "eventId") &&
    hasStringProp(v, "eventTitle") &&
    hasStringProp(v, "endsAt") &&
    hasBooleanProp(v, "ended") &&
    hasNumberProp(v, "distributed") &&
    hasNumberProp(v, "inCirculation") &&
    hasNumberProp(v, "converted") &&
    hasNumberProp(v, "destroyed") &&
    "participants" in v &&
    Array.isArray(v.participants) &&
    v.participants.every(isParticipant)
  );
}

export async function fetchEventPelles(eventId: string): Promise<EventPelles | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/pelles`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isEventPelles(payload) ? payload : null;
  } catch {
    return null;
  }
}

/** The server owns the rules (bounds, ended event, selection); this relays its message. */
export async function distributeEventPelles(eventId: string, input: DistributeInput): Promise<DistributeResult> {
  try {
    const body: Record<string, unknown> = { amount: input.amount, label: input.label.trim(), requestId: input.requestId };
    if (input.userIds !== null) body.userIds = input.userIds;
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/pelles`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
    const payload: unknown = await res.json().catch(() => null);

    if (
      res.ok &&
      typeof payload === "object" &&
      payload !== null &&
      hasNumberProp(payload, "credited") &&
      hasNumberProp(payload, "skipped") &&
      hasNumberProp(payload, "alreadyCredited")
    ) {
      return { kind: "ok", credited: payload.credited, skipped: payload.skipped, alreadyCredited: payload.alreadyCredited };
    }
    if (
      typeof payload === "object" &&
      payload !== null &&
      "error" in payload &&
      typeof payload.error === "object" &&
      payload.error !== null &&
      hasStringProp(payload.error, "message")
    ) {
      return { kind: "error", message: payload.error.message };
    }
    return { kind: "error", message: "La distribution a échoué." };
  } catch {
    return { kind: "error", message: "Impossible de contacter l'API." };
  }
}
