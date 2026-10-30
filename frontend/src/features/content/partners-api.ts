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

function dataOf(payload: unknown): unknown {
  return typeof payload === "object" && payload !== null && "data" in payload ? payload.data : undefined;
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
