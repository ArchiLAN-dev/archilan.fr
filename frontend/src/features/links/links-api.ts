import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

/** Story 55.1: a tracked link `/l/{code}`, as the admin sees it. */
export type TrackedLink = {
  id: string;
  code: string;
  label: string;
  /** A path of the site (inbound link) or an https address (outbound link). */
  target: string;
  direction: "inbound" | "outbound";
  active: boolean;
  createdAt: string;
  /** Story 55.2: passes counted, the last 7 and 30 days counting today (Paris). */
  visits: LinkVisits;
};

export type LinkVisits = { total: number; last7: number; last30: number };

/** Story 55.2: one link over 90 days, one entry per day (zero included), and where its visitors came from. */
export type LinkStats = {
  days: { day: string; visits: number }[];
  /** An empty host is a visitor whose browser did not say where it came from. */
  origins: { host: string; visits: number }[];
};

export type TrackedLinkFields = { code: string; label: string; target: string; active: boolean };

export type SaveLinkResult = { ok: true; id: string } | { ok: false; message: string };

export function isTrackedLink(v: unknown): v is TrackedLink {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "id") &&
    hasStringProp(v, "code") &&
    hasStringProp(v, "label") &&
    hasStringProp(v, "target") &&
    hasStringProp(v, "createdAt") &&
    hasBooleanProp(v, "active") &&
    "direction" in v &&
    (v.direction === "inbound" || v.direction === "outbound") &&
    "visits" in v &&
    isLinkVisits(v.visits)
  );
}

function isLinkVisits(v: unknown): v is LinkVisits {
  return typeof v === "object" && v !== null && hasNumberProp(v, "total") && hasNumberProp(v, "last7") && hasNumberProp(v, "last30");
}

function isDayCount(v: unknown): v is { day: string; visits: number } {
  return typeof v === "object" && v !== null && hasStringProp(v, "day") && hasNumberProp(v, "visits");
}

function isOriginCount(v: unknown): v is { host: string; visits: number } {
  return typeof v === "object" && v !== null && hasStringProp(v, "host") && hasNumberProp(v, "visits");
}

export function isLinkStats(v: unknown): v is LinkStats {
  return (
    typeof v === "object" &&
    v !== null &&
    "days" in v &&
    Array.isArray(v.days) &&
    v.days.every(isDayCount) &&
    "origins" in v &&
    Array.isArray(v.origins) &&
    v.origins.every(isOriginCount)
  );
}

function dataOf(payload: unknown): unknown {
  return typeof payload === "object" && payload !== null && "data" in payload ? payload.data : undefined;
}

function errorMessage(payload: unknown, fallback: string): string {
  if (typeof payload === "object" && payload !== null && "error" in payload && typeof payload.error === "object" && payload.error !== null && hasStringProp(payload.error, "message")) {
    return payload.error.message;
  }
  return fallback;
}

/** Newest first; null when the read failed. */
export async function fetchAdminLinks(): Promise<TrackedLink[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/links`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return Array.isArray(data) ? data.filter(isTrackedLink) : null;
  } catch {
    return null;
  }
}

/** Story 55.2: the visits of one link over 90 days; null when the read failed. */
export async function fetchLinkStats(id: string): Promise<LinkStats | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/links/${id}/stats`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return isLinkStats(data) ? { days: data.days, origins: data.origins } : null;
  } catch {
    return null;
  }
}

/** Creates the link, or edits it when `id` is given (its code then stays). */
export async function saveLink(fields: TrackedLinkFields, id: string | null): Promise<SaveLinkResult> {
  try {
    const res = await apiFetch(id === null ? `${env.apiBaseUrl}/admin/links` : `${env.apiBaseUrl}/admin/links/${id}`, {
      method: id === null ? "POST" : "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(fields),
    });
    if (res.status === 204 && id !== null) return { ok: true, id };
    const payload: unknown = await res.json().catch(() => null);
    const data = dataOf(payload);
    if (res.status === 201 && typeof data === "object" && data !== null && hasStringProp(data, "id")) return { ok: true, id: data.id };
    return { ok: false, message: errorMessage(payload, "Le lien n'a pas pu être enregistré.") };
  } catch {
    return { ok: false, message: "Impossible de contacter l'API." };
  }
}

/** How long the redirect waits for the API before sending the visitor home. */
const VISIT_TIMEOUT_MS = 3000;

/**
 * Server side, from `/l/{code}`: records the pass (when `count`) and reads the target. Null when the link is unknown,
 * turned off, or the API does not answer in time: the visitor then goes home.
 */
export async function recordLinkVisit(code: string, referrerHost: string, count: boolean): Promise<string | null> {
  try {
    const res = await fetch(`${env.apiBaseUrl}/links/${encodeURIComponent(code)}/visit`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ referrerHost, count }),
      cache: "no-store",
      signal: AbortSignal.timeout(VISIT_TIMEOUT_MS),
    });
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return typeof data === "object" && data !== null && hasStringProp(data, "target") ? data.target : null;
  } catch {
    return null;
  }
}
