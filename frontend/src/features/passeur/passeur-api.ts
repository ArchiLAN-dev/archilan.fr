import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

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
};

export type PasseurState = { enabled: boolean; offer: PasseurOffer | null };

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
    (v.nextDropAt === null || typeof v.nextDropAt === "string")
  );
}

export function isPasseurState(v: unknown): v is PasseurState {
  return isObject(v) && hasBooleanProp(v, "enabled") && "offer" in v && (v.offer === null || isPasseurOffer(v.offer));
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
