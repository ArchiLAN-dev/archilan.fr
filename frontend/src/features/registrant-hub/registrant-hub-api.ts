import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasStringProp } from "@/lib/type-guards";

/**
 * Story 53.1: who may open the registrant hub of an event. Unlike the event-page CTA, a failure is an `error` and
 * never a "not registered": a registrant on a flaky LAN must not be bounced off their own hub.
 */
export type RegistrantHubAccess =
  | { kind: "guest" }
  | { kind: "not_registered" }
  | { kind: "registered"; registrationId: string; confirmed: boolean }
  | { kind: "error" };

export async function fetchRegistrantHubAccess(eventId: string): Promise<RegistrantHubAccess> {
  try {
    const profile = await apiFetch(`${env.apiBaseUrl}/account/profile`);
    if (profile.status === 401 || profile.status === 403) return { kind: "guest" };
    if (!profile.ok) return { kind: "error" };

    const registration = await apiFetch(`${env.apiBaseUrl}/events/${eventId}/my-registration`);
    if (registration.status === 404) return { kind: "not_registered" };
    if (!registration.ok) return { kind: "error" };

    return parseRegistrantHubAccess(await registration.json());
  } catch {
    return { kind: "error" };
  }
}

export function parseRegistrantHubAccess(payload: unknown): RegistrantHubAccess {
  const data = typeof payload === "object" && payload !== null && "data" in payload ? payload.data : null;
  if (typeof data !== "object" || data === null || !hasStringProp(data, "registrationId")) return { kind: "error" };
  // A cancelled registration is no registration (story 4.22).
  if (hasStringProp(data, "status") && data.status === "cancelled") return { kind: "not_registered" };
  return { kind: "registered", registrationId: data.registrationId, confirmed: hasBooleanProp(data, "confirmed") && data.confirmed };
}

/** Where a visitor who may not see the hub goes instead, or null to stay. */
export function hubRedirect(access: RegistrantHubAccess, eventSlug: string): string | null {
  if (access.kind === "guest") return `/connexion?returnTo=${encodeURIComponent(registrantHubPath(eventSlug))}`;
  if (access.kind === "not_registered") return `/evenements/${eventSlug}`;
  return null;
}

export function registrantHubPath(eventSlug: string): string {
  return `/evenements/${eventSlug}/mon-event`;
}
