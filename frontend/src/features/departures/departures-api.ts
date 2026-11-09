import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/** Story 52.6: whether the player of a slot may say they leave, and when they already did. */
export type MyDeparture = { available: boolean; requestedAt: string | null };

/** Story 52.6: a departure to handle - `schedule` when the declared departure passed with the slot offline. */
export type Departure = { id: string; slotIndex: number; slotName: string; origin: "player" | "schedule"; createdAt: string };

function isObject(v: unknown): v is object {
  return typeof v === "object" && v !== null;
}

function dataOf(payload: unknown): unknown {
  return isObject(payload) && "data" in payload ? payload.data : null;
}

function errorMessage(payload: unknown, fallback: string): string {
  return isObject(payload) && "error" in payload && isObject(payload.error) && hasStringProp(payload.error, "message") ? payload.error.message : fallback;
}

export function isMyDeparture(v: unknown): v is MyDeparture {
  return isObject(v) && hasBooleanProp(v, "available") && hasNullableStringProp(v, "requestedAt");
}

export function isDeparture(v: unknown): v is Departure {
  return (
    isObject(v) &&
    hasStringProp(v, "id") &&
    hasNumberProp(v, "slotIndex") &&
    hasStringProp(v, "slotName") &&
    "origin" in v &&
    (v.origin === "player" || v.origin === "schedule") &&
    hasStringProp(v, "createdAt")
  );
}

export function myDepartureKey(sessionId: string, slotIndex: string): readonly ["my-departure", string, string] {
  return ["my-departure", sessionId, slotIndex];
}

export function departuresKey(sessionId: string): readonly ["departures", string] {
  return ["departures", sessionId];
}

export async function fetchMyDeparture(sessionId: string, slotIndex: string): Promise<MyDeparture | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/slots/${slotIndex}/departure`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return isMyDeparture(data) ? data : null;
  } catch {
    return null;
  }
}

/** Null when said, otherwise the message to show. */
export async function sayILeave(sessionId: string, slotIndex: string): Promise<string | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/slots/${slotIndex}/departure`, { method: "POST" });
    if (res.status === 204) return null;
    return errorMessage(await res.json().catch(() => null), "Ton départ n'a pas pu être signalé.");
  } catch {
    return "Le serveur est injoignable.";
  }
}

/** Null for a viewer who may not handle them (or on failure): the list stays hidden. */
export async function fetchDepartures(sessionId: string): Promise<Departure[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/departures`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return Array.isArray(data) && data.every(isDeparture) ? data : null;
  } catch {
    return null;
  }
}

/** Null when done, otherwise the message to show (a bridge that did not answer leaves the departure open). */
export async function decideDeparture(sessionId: string, departureId: string, decision: "release" | "dismiss"): Promise<string | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/sessions/${sessionId}/departures/${departureId}/${decision}`, { method: "POST" });
    if (res.status === 204) return null;
    return errorMessage(await res.json().catch(() => null), decision === "release" ? "Le monde n'a pas pu être libéré." : "Le départ n'a pas pu être écarté.");
  } catch {
    return "Le serveur est injoignable.";
  }
}
