import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasStringProp } from "@/lib/type-guards";

/** Story 53.5: a published help guide, as its card shows it. */
export type HelpGuideCard = {
  slug: string;
  title: string;
  summary: string;
};

export type HelpGuide = HelpGuideCard & {
  body: string;
  updatedAt: string;
};

export type AdminHelpGuide = HelpGuide & {
  id: string;
  published: boolean;
};

export type HelpGuideFields = Pick<AdminHelpGuide, "slug" | "title" | "summary" | "body" | "published">;

/** Same bounds as the API (`HelpGuide`). */
export const HELP_GUIDE_LIMITS = { slug: 60, title: 80, summary: 200, body: 20000 } as const;

/** Story 53.5: the cache tag of every public read of the guides, dropped by the API on each admin write. */
export const HELP_GUIDES_TAG = "guides";

/** The slugs the site links to from its tool cards. A card links only when its guide is published. */
export const HELP_GUIDE_SLUGS = {
  firstGame: "premiere-partie",
  pelles: "pelles",
  passeur: "passeur",
  blindTest: "blind-test",
  minimapperz: "minimapperz",
  broadcast: "diffusion",
} as const;

export function helpGuidePath(slug: string): string {
  return `/aide/${slug}`;
}

export function isHelpGuideCard(v: unknown): v is HelpGuideCard {
  return typeof v === "object" && v !== null && hasStringProp(v, "slug") && hasStringProp(v, "title") && hasStringProp(v, "summary");
}

export function isHelpGuide(v: unknown): v is HelpGuide {
  return isHelpGuideCard(v) && hasStringProp(v, "body") && hasStringProp(v, "updatedAt");
}

export function isAdminHelpGuide(v: unknown): v is AdminHelpGuide {
  return isHelpGuide(v) && hasStringProp(v, "id") && hasBooleanProp(v, "published");
}

function dataOf(payload: unknown): unknown {
  return typeof payload === "object" && payload !== null && "data" in payload ? payload.data : undefined;
}

/** The published guides, read on the server and kept five minutes like the pages showing them. Fails soft: []. */
export async function getHelpGuides(): Promise<HelpGuideCard[]> {
  try {
    const res = await fetch(`${env.apiBaseUrl}/help-guides`, { next: { revalidate: 300, tags: [HELP_GUIDES_TAG] } });
    if (!res.ok) return [];
    const data = dataOf(await res.json());
    return Array.isArray(data) ? data.filter(isHelpGuideCard) : [];
  } catch {
    return [];
  }
}

/** One published guide, read on the server; null when it is a draft, unknown, or the read failed. */
export async function getHelpGuide(slug: string): Promise<HelpGuide | null> {
  try {
    const res = await fetch(`${env.apiBaseUrl}/help-guides/${encodeURIComponent(slug)}`, {
      next: { revalidate: 300, tags: [HELP_GUIDES_TAG, `guide:${slug}`] },
    });
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return isHelpGuide(data) ? data : null;
  } catch {
    return null;
  }
}

/** The published guides read from the browser (the registrant space); null when the read failed. */
export async function fetchHelpGuides(): Promise<HelpGuideCard[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/help-guides`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return Array.isArray(data) ? data.filter(isHelpGuideCard) : null;
  } catch {
    return null;
  }
}

/** The admin list, in display order; null when the read failed. */
export async function fetchAdminHelpGuides(): Promise<AdminHelpGuide[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/help-guides`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return Array.isArray(data) ? data.filter(isAdminHelpGuide) : null;
  } catch {
    return null;
  }
}

export type SaveHelpGuideResult = { ok: true; id: string } | { ok: false; message: string };

/** Creates the guide, or edits it when `id` is given. */
export async function saveHelpGuide(fields: HelpGuideFields, id: string | null): Promise<SaveHelpGuideResult> {
  try {
    const res = await apiFetch(id === null ? `${env.apiBaseUrl}/admin/help-guides` : `${env.apiBaseUrl}/admin/help-guides/${id}`, {
      method: id === null ? "POST" : "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(fields),
    });
    if (res.status === 204 && id !== null) return { ok: true, id };
    const payload: unknown = await res.json().catch(() => null);
    const data = dataOf(payload);
    if (res.status === 201 && typeof data === "object" && data !== null && hasStringProp(data, "id")) return { ok: true, id: data.id };
    return { ok: false, message: errorMessage(payload, "Le guide n'a pas pu être enregistré.") };
  } catch {
    return { ok: false, message: "Impossible de contacter l'API." };
  }
}

export async function deleteHelpGuide(id: string): Promise<string | null> {
  return send(`${env.apiBaseUrl}/admin/help-guides/${id}`, { method: "DELETE" }, "Le guide n'a pas pu être supprimé.");
}

/** Every guide in its new order: each takes its rank. */
export async function reorderHelpGuides(ids: string[]): Promise<string | null> {
  return send(
    `${env.apiBaseUrl}/admin/help-guides/reorder`,
    { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ ids }) },
    "L'ordre n'a pas pu être enregistré.",
  );
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
