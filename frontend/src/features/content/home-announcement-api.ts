import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasNullableStringProp, hasStringProp } from "@/lib/type-guards";

/** Story 10.14: the announcement opened as a modal on the home page. */
export type HomeAnnouncement = {
  id: string;
  title: string;
  body: string;
  imageUrl: string | null;
  ctaLabel: string;
  ctaUrl: string;
  startsAt: string;
  endsAt: string;
};

export type AdminHomeAnnouncementStatus = "upcoming" | "running" | "ended";

export type AdminHomeAnnouncement = HomeAnnouncement & { status: AdminHomeAnnouncementStatus };

export type HomeAnnouncementFields = Pick<HomeAnnouncement, "title" | "body" | "ctaLabel" | "ctaUrl" | "startsAt" | "endsAt">;

const STATUSES: readonly string[] = ["upcoming", "running", "ended"];

export function isHomeAnnouncement(v: unknown): v is HomeAnnouncement {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "id") &&
    hasStringProp(v, "title") &&
    hasStringProp(v, "body") &&
    hasNullableStringProp(v, "imageUrl") &&
    hasStringProp(v, "ctaLabel") &&
    hasStringProp(v, "ctaUrl") &&
    hasStringProp(v, "startsAt") &&
    hasStringProp(v, "endsAt")
  );
}

function isAdminHomeAnnouncement(v: unknown): v is AdminHomeAnnouncement {
  return isHomeAnnouncement(v) && "status" in v && typeof v.status === "string" && STATUSES.includes(v.status);
}

function dataOf(payload: unknown): unknown {
  return typeof payload === "object" && payload !== null && "data" in payload ? payload.data : undefined;
}

/**
 * Read on the server with the home page, kept five minutes like it (ISR). The API also hands the next announcement
 * starting within a day, so the browser can open it on time. Fails soft: null folds the modal away.
 */
export async function getHomeAnnouncement(): Promise<HomeAnnouncement | null> {
  try {
    const res = await fetch(`${env.apiBaseUrl}/home-announcement`, { next: { revalidate: 300 } });
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return isHomeAnnouncement(data) ? data : null;
  } catch {
    return null;
  }
}

/** The admin list, newest window first; null when the read failed. */
export async function fetchAdminHomeAnnouncements(): Promise<AdminHomeAnnouncement[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/announcements`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return Array.isArray(data) ? data.filter(isAdminHomeAnnouncement) : null;
  } catch {
    return null;
  }
}

export type SaveResult = { ok: true; id: string } | { ok: false; message: string };

/** Creates the announcement, or edits it when `id` is given. */
export async function saveHomeAnnouncement(fields: HomeAnnouncementFields, id: string | null): Promise<SaveResult> {
  try {
    const res = await apiFetch(id === null ? `${env.apiBaseUrl}/admin/announcements` : `${env.apiBaseUrl}/admin/announcements/${id}`, {
      method: id === null ? "POST" : "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(fields),
    });
    if (res.status === 204 && id !== null) return { ok: true, id };
    const payload: unknown = await res.json().catch(() => null);
    const data = dataOf(payload);
    if (res.status === 201 && typeof data === "object" && data !== null && hasStringProp(data, "id")) return { ok: true, id: data.id };
    return { ok: false, message: errorMessage(payload, "L'annonce n'a pas pu être enregistrée.") };
  } catch {
    return { ok: false, message: "Impossible de contacter l'API." };
  }
}

export async function deleteHomeAnnouncement(id: string): Promise<string | null> {
  return send(`${env.apiBaseUrl}/admin/announcements/${id}`, { method: "DELETE" }, "L'annonce n'a pas pu être supprimée.");
}

export async function uploadHomeAnnouncementImage(id: string, file: File): Promise<string | null> {
  const body = new FormData();
  body.append("file", file);
  return send(`${env.apiBaseUrl}/admin/announcements/${id}/image`, { method: "POST", body }, "L'image n'a pas pu être envoyée.");
}

export async function removeHomeAnnouncementImage(id: string): Promise<string | null> {
  return send(`${env.apiBaseUrl}/admin/announcements/${id}/image`, { method: "DELETE" }, "L'image n'a pas pu être retirée.");
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
