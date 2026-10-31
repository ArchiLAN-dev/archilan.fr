import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp } from "@/lib/type-guards";

/** Story 47.1: the member's consent to the ArchiLAN broadcast showing their stream, and whether it can still change. */
export type BroadcastConsent = { consent: boolean; editable: boolean };

export function broadcastConsentKey(registrationId: string): readonly ["broadcast-consent", string] {
  return ["broadcast-consent", registrationId];
}

export function isBroadcastConsent(v: unknown): v is BroadcastConsent {
  return typeof v === "object" && v !== null && hasBooleanProp(v, "consent") && hasBooleanProp(v, "editable");
}

/** Null when signed out, not the member's registration, or unreadable: the card then stays hidden. */
export async function fetchBroadcastConsent(registrationId: string): Promise<BroadcastConsent | null> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/registrations/${registrationId}/broadcast-consent`);
    if (!response.ok) return null;
    const payload: unknown = await response.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return null;
    return isBroadcastConsent(payload.data) ? payload.data : null;
  } catch {
    return null;
  }
}

/** Returns null on success, otherwise the message to show. */
export async function saveBroadcastConsent(registrationId: string, consent: boolean): Promise<string | null> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/registrations/${registrationId}/broadcast-consent`, {
      body: JSON.stringify({ consent }),
      headers: { "Content-Type": "application/json" },
      method: "PUT",
    });
    if (response.ok) return null;
    return response.status === 422 ? "Ce choix n'est plus modifiable : l'inscription est annulée ou l'événement est terminé." : "Le choix n'a pas pu être enregistré.";
  } catch {
    return "Le serveur est injoignable.";
  }
}
