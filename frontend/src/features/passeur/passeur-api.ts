import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/** Story 52.4: the chest the Passeur proposes to open for a blocked player, at the price of the moment. */
export type PasseurOffer = {
  findingSlot: number;
  absentPlayerName: string;
  locationId: number;
  locationName: string;
  itemName: string;
  price: number;
  basePrice: number;
  kind: "event" | "bronze";
  balance: number;
  blockedSince: string;
  nextDropAt: string | null;
  /** Story 52.9: set while the player has not been in BK long enough; the call opens then. */
  availableAt?: string | null;
};

/** Story 11.10: `canCall` false for a co-player of the slot (only its holder calls); absent on older payloads. */
export type PasseurState = { enabled: boolean; canCall?: boolean; offer: PasseurOffer | null };

export type PasseurCallResult =
  | { kind: "ok"; itemName: string; absentPlayerName: string; price: number }
  | { kind: "error"; code: string; message: string; price: number | null };

export function passeurKey(sessionId: string, slotIndex: string): readonly ["passeur", string, string] {
  return ["passeur", sessionId, slotIndex];
}

function isObject(v: unknown): v is object {
  return typeof v === "object" && v !== null;
}

export function isPasseurOffer(v: unknown): v is PasseurOffer {
  return (
    isObject(v) &&
    hasNumberProp(v, "findingSlot") &&
    hasStringProp(v, "absentPlayerName") &&
    hasNumberProp(v, "locationId") &&
    hasStringProp(v, "locationName") &&
    hasStringProp(v, "itemName") &&
    hasNumberProp(v, "price") &&
    hasNumberProp(v, "basePrice") &&
    "kind" in v &&
    (v.kind === "event" || v.kind === "bronze") &&
    hasNumberProp(v, "balance") &&
    hasStringProp(v, "blockedSince") &&
    "nextDropAt" in v &&
    (v.nextDropAt === null || typeof v.nextDropAt === "string") &&
    (!("availableAt" in v) || v.availableAt === null || typeof v.availableAt === "string")
  );
}

export function isPasseurState(v: unknown): v is PasseurState {
  return (
    isObject(v) &&
    hasBooleanProp(v, "enabled") &&
    (!("canCall" in v) || typeof v.canCall === "boolean") &&
    "offer" in v &&
    (v.offer === null || isPasseurOffer(v.offer))
  );
}

/** Null when the player may not see it, or the party cannot say: the panel stays hidden. */
export async function fetchPasseur(sessionId: string, slotIndex: string): Promise<PasseurState | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/slots/${slotIndex}/passeur`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (!isObject(payload) || !("data" in payload)) return null;
    return isPasseurState(payload.data) ? payload.data : null;
  } catch {
    return null;
  }
}

export async function callThePasseur(sessionId: string, slotIndex: string, offer: PasseurOffer, requestId: string): Promise<PasseurCallResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/slots/${slotIndex}/passeur`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ findingSlot: offer.findingSlot, locationId: offer.locationId, price: offer.price, requestId }),
    });
    const payload: unknown = await res.json().catch(() => null);
    if (res.ok && isObject(payload) && "data" in payload && isObject(payload.data) && hasStringProp(payload.data, "itemName") && hasStringProp(payload.data, "absentPlayerName") && hasNumberProp(payload.data, "price")) {
      return { kind: "ok", itemName: payload.data.itemName, absentPlayerName: payload.data.absentPlayerName, price: payload.data.price };
    }
    const error = isObject(payload) && "error" in payload && isObject(payload.error) ? payload.error : null;
    const details = error !== null && "details" in error && isObject(error.details) ? error.details : null;
    return {
      kind: "error",
      code: error !== null && hasStringProp(error, "code") ? error.code : "passeur_failed",
      message: error !== null && hasStringProp(error, "message") ? error.message : "Le Passeur n'a pas pu intervenir.",
      price: details !== null && hasNumberProp(details, "price") ? details.price : null,
    };
  } catch {
    return { kind: "error", code: "network_error", message: "Le serveur est injoignable.", price: null };
  }
}

/** Story 52.5: one call to the Passeur, as the journals and the opened world's player's notice show it. */
export type PasseurOpening = {
  id: string;
  sessionId: string;
  status: "pending" | "opened" | "failed";
  buyerName: string;
  absentName: string;
  locationName: string;
  itemName: string;
  kind: "event" | "bronze";
  price: number;
  share: number;
  failureCode: string | null;
  createdAt: string;
  settledAt: string | null;
};

export type PasseurNotice = { openings: PasseurOpening[]; total: number; kind: "event" | "bronze" | null };

export function isPasseurOpening(v: unknown): v is PasseurOpening {
  return (
    isObject(v) &&
    hasStringProp(v, "id") &&
    hasStringProp(v, "sessionId") &&
    "status" in v &&
    (v.status === "pending" || v.status === "opened" || v.status === "failed") &&
    hasStringProp(v, "buyerName") &&
    hasStringProp(v, "absentName") &&
    hasStringProp(v, "locationName") &&
    hasStringProp(v, "itemName") &&
    "kind" in v &&
    (v.kind === "event" || v.kind === "bronze") &&
    hasNumberProp(v, "price") &&
    hasNumberProp(v, "share") &&
    hasNullableStringProp(v, "failureCode") &&
    hasStringProp(v, "createdAt") &&
    hasNullableStringProp(v, "settledAt")
  );
}

export function isPasseurNotice(v: unknown): v is PasseurNotice {
  return (
    isObject(v) &&
    "openings" in v &&
    Array.isArray(v.openings) &&
    v.openings.every(isPasseurOpening) &&
    hasNumberProp(v, "total") &&
    "kind" in v &&
    (v.kind === null || v.kind === "event" || v.kind === "bronze")
  );
}

export function passeurNoticeKey(sessionId: string): readonly ["passeur-notice", string] {
  return ["passeur-notice", sessionId];
}

export async function fetchPasseurNotice(sessionId: string): Promise<PasseurNotice | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/passeur/notice`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isObject(payload) && "data" in payload && isPasseurNotice(payload.data) ? payload.data : null;
  } catch {
    return null;
  }
}

/** True when the notice is closed. */
export async function dismissPasseurNotice(sessionId: string): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/passeur/notice`, { method: "DELETE" });
    return res.status === 204;
  } catch {
    return false;
  }
}

/** Where a journal is read: an event or a session for the staff, a private run for its owner. */
export type PasseurJournalScope = { kind: "event"; eventId: string } | { kind: "session"; sessionId: string } | { kind: "run"; runId: string };

function journalUrl(scope: PasseurJournalScope): string {
  if (scope.kind === "event") return `${env.apiBaseUrl}/admin/events/${scope.eventId}/passeur-openings`;
  if (scope.kind === "session") return `${env.apiBaseUrl}/admin/sessions/${scope.sessionId}/passeur-openings`;
  return `${env.apiBaseUrl}/runs/${scope.runId}/passeur-openings`;
}

export async function fetchPasseurJournal(scope: PasseurJournalScope): Promise<PasseurOpening[] | null> {
  try {
    const res = await apiFetch(journalUrl(scope));
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isObject(payload) && "data" in payload && Array.isArray(payload.data) && payload.data.every(isPasseurOpening) ? payload.data : null;
  } catch {
    return null;
  }
}
