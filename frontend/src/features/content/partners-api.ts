import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasStringProp } from "@/lib/type-guards";

/** Story 45.1: a partner of the association, as visitors see it. */
export type Partner = {
  id: string;
  name: string;
  description: string;
  url: string;
  logoUrl: string | null;
};

/** `visible` = shown by the admin and with a logo: what the public list keeps. */
export type AdminPartner = Partner & { active: boolean; visible: boolean };

export type PartnerFields = Pick<AdminPartner, "name" | "description" | "url" | "active">;

/** Story 45.3: a partner thanked on an event, by reference, with the event's own mention. */
export type EventPartnerRef = { partnerId: string; mention: string | null };

/** An event partner ready to show: the visible partner and the event's mention. */
export type EventPartner = { partner: Partner; mention: string | null };

/** Story 45.2: the cache tag of every public read of the partners, dropped by the API on each admin write. */
export const PARTNERS_TAG = "partners";

export function isPartner(v: unknown): v is Partner {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "id") &&
    hasStringProp(v, "name") &&
    hasStringProp(v, "description") &&
    hasStringProp(v, "url") &&
    hasNullableStringProp(v, "logoUrl")
  );
}

export function isAdminPartner(v: unknown): v is AdminPartner {
  return isPartner(v) && hasBooleanProp(v, "active") && hasBooleanProp(v, "visible");
}

export function isEventPartnerRef(v: unknown): v is EventPartnerRef {
  return typeof v === "object" && v !== null && hasStringProp(v, "partnerId") && hasNullableStringProp(v, "mention");
}

/**
 * Story 45.3: the event's partners in the event's order, kept only when the partner is visible. A partner hidden,
 * without a logo or deleted drops out here, so past events follow the admin without any write on their side.
 */
export function joinEventPartners(refs: EventPartnerRef[], partners: Partner[]): EventPartner[] {
  const byId = new Map(partners.map((partner) => [partner.id, partner]));
  return refs.flatMap((ref) => {
    const partner = byId.get(ref.partnerId);
    return partner === undefined ? [] : [{ partner, mention: ref.mention }];
  });
}

function dataOf(payload: unknown): unknown {
  return typeof payload === "object" && payload !== null && "data" in payload ? payload.data : undefined;
}

/**
 * The visible partners, in display order, read on the server and kept five minutes like the pages showing them
 * (ISR). Fails soft: an empty list folds every partner block away.
 */
export async function getPartners(): Promise<Partner[]> {
  try {
    const res = await fetch(`${env.apiBaseUrl}/partners`, { next: { revalidate: 300, tags: [PARTNERS_TAG] } });
    if (!res.ok) return [];
    const data = dataOf(await res.json());
    return Array.isArray(data) ? data.filter(isPartner) : [];
  } catch {
    return [];
  }
}

/**
 * Story 45.4: the visible partners read from the browser, for the OBS / LAN screen that stays open for hours and
 * re-reads the list itself. null when the read failed, so the screen keeps what it shows.
 */
export async function fetchPartners(): Promise<Partner[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/partners`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return Array.isArray(data) ? data.filter(isPartner) : null;
  } catch {
    return null;
  }
}

/** The admin list, in display order; null when the read failed. */
export async function fetchAdminPartners(): Promise<AdminPartner[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/partners`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return Array.isArray(data) ? data.filter(isAdminPartner) : null;
  } catch {
    return null;
  }
}

/** Story 45.3: the partners chosen for an event; null when the read failed. */
export async function fetchAdminEventPartners(eventId: string): Promise<EventPartnerRef[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/partners`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return Array.isArray(data) ? data.filter(isEventPartnerRef) : null;
  } catch {
    return null;
  }
}

/** null on success, the API's message otherwise. */
export async function saveEventPartners(eventId: string, partners: EventPartnerRef[]): Promise<string | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/partners`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ partners }),
    });
    if (res.ok) return null;
    return errorMessage(await res.json().catch(() => null), "Les partenaires n'ont pas pu être enregistrés.");
  } catch {
    return "Impossible de contacter l'API.";
  }
}

export type SaveResult = { ok: true; id: string } | { ok: false; message: string };

/** Creates the partner, or edits it when `id` is given. */
export async function savePartner(fields: PartnerFields, id: string | null): Promise<SaveResult> {
  try {
    const res = await apiFetch(id === null ? `${env.apiBaseUrl}/admin/partners` : `${env.apiBaseUrl}/admin/partners/${id}`, {
      method: id === null ? "POST" : "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(fields),
    });
    if (res.status === 204 && id !== null) return { ok: true, id };
    const payload: unknown = await res.json().catch(() => null);
    const data = dataOf(payload);
    if (res.status === 201 && typeof data === "object" && data !== null && hasStringProp(data, "id")) return { ok: true, id: data.id };
    return { ok: false, message: errorMessage(payload, "Le partenaire n'a pas pu être enregistré.") };
  } catch {
    return { ok: false, message: "Impossible de contacter l'API." };
  }
}

export async function deletePartner(id: string): Promise<string | null> {
  return send(`${env.apiBaseUrl}/admin/partners/${id}`, { method: "DELETE" }, "Le partenaire n'a pas pu être supprimé.");
}

/** Every partner in its new order: each takes its rank. */
export async function reorderPartners(ids: string[]): Promise<string | null> {
  return send(
    `${env.apiBaseUrl}/admin/partners/reorder`,
    { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ ids }) },
    "L'ordre n'a pas pu être enregistré.",
  );
}

export async function uploadPartnerLogo(id: string, file: File): Promise<string | null> {
  const body = new FormData();
  body.append("file", file);
  return send(`${env.apiBaseUrl}/admin/partners/${id}/logo`, { method: "POST", body }, "Le logo n'a pas pu être envoyé.");
}

export async function removePartnerLogo(id: string): Promise<string | null> {
  return send(`${env.apiBaseUrl}/admin/partners/${id}/logo`, { method: "DELETE" }, "Le logo n'a pas pu être retiré.");
}

/** null on success, the API's message (or the fallback) otherwise. */
async function send(url: string, init: RequestInit, fallback: string): Promise<string | null> {
  try {
    const res = await apiFetch(url, init);
    if (res.status === 204) return null;
    return errorMessage(await res.json().catch(() => null), fallback);
  } catch {
    return "Impossible de contacter l'API.";
  }
}

function errorMessage(payload: unknown, fallback: string): string {
  if (typeof payload === "object" && payload !== null && "error" in payload && typeof payload.error === "object" && payload.error !== null && hasStringProp(payload.error, "message")) {
    return payload.error.message;
  }
  return fallback;
}
