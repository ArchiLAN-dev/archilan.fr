import { getPublicEvent } from "@/features/events/public-events-api";
import { fetchPartners, joinEventPartners, type EventPartner } from "./partners-api";

/**
 * Story 45.4: what the partner screen shows. With an event, its partners in its order and with its mentions (an
 * unknown or private event shows none: no fallback to every partner); without, every visible partner. null when a
 * read failed (a missing event reads the same), so the screen keeps its last list instead of going blank on air.
 */
export async function fetchPartnerScreen(eventId: string | null): Promise<EventPartner[] | null> {
  const [partners, event] = await Promise.all([fetchPartners(), eventId === null ? Promise.resolve(null) : getPublicEvent(eventId)]);
  if (partners === null) return null;
  if (eventId === null) return partners.map((partner) => ({ partner, mention: null }));
  return event === null ? null : joinEventPartners(event.partners ?? [], partners);
}
