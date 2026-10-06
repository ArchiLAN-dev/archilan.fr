import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";
import type { AvatarFrameAccess } from "./avatar-frame-catalog";

/** Story 41.22: a profile title the admins write, worn under the name. */
export type ProfileTitle = { key: string; label: string; access: AvatarFrameAccess };

export type AdminProfileTitle = ProfileTitle & { retired: boolean; position: number };

export const PROFILE_TITLE_CATALOG_QUERY_KEY = ["profile-title-catalog"] as const;
export const ADMIN_PROFILE_TITLES_QUERY_KEY = ["admin-profile-titles"] as const;

export const PROFILE_TITLE_LIMITS = { minLabel: 2, maxLabel: 40 } as const;

export const TITLE_ACCESS_LABELS: Record<AvatarFrameAccess, string> = {
  free: "Tout le monde",
  members: "Adhérents",
  admins: "Admins",
  shop: "Boutique",
};

const ACCESSES: readonly AvatarFrameAccess[] = ["free", "members", "admins", "shop"];

function isTitle(v: unknown): v is ProfileTitle {
  return typeof v === "object" && v !== null && hasStringProp(v, "key") && hasStringProp(v, "label") && hasStringProp(v, "access") && ACCESSES.some((a) => a === v.access);
}

function isAdminTitle(v: unknown): v is AdminProfileTitle {
  return isTitle(v) && hasBooleanProp(v, "retired") && hasNumberProp(v, "position");
}

/** The titles that can be worn; empty when the API is out of reach. */
export async function fetchProfileTitleCatalog(): Promise<ProfileTitle[]> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/profile-titles`);
    if (!res.ok) return [];
    const payload: unknown = await res.json();
    return typeof payload === "object" && payload !== null && "titles" in payload && Array.isArray(payload.titles) && payload.titles.every(isTitle) ? payload.titles : [];
  } catch {
    return [];
  }
}

export async function fetchAdminProfileTitles(): Promise<AdminProfileTitle[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/profile-titles`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return typeof payload === "object" && payload !== null && "titles" in payload && Array.isArray(payload.titles) && payload.titles.every(isAdminTitle) ? payload.titles : null;
  } catch {
    return null;
  }
}

/** Each write answers null when done, or the API's message. */
async function send(path: string, init: RequestInit, expected: number, fallback: string): Promise<string | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}${path}`, init);
    if (res.status === expected) return null;
    const payload: unknown = await res.json().catch(() => null);
    if (typeof payload === "object" && payload !== null && "error" in payload && typeof payload.error === "object" && payload.error !== null && hasStringProp(payload.error, "message")) {
      return payload.error.message;
    }
    return fallback;
  } catch {
    return "Impossible de contacter l'API.";
  }
}

function json(method: string, body: unknown): RequestInit {
  return { method, headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) };
}

export function writeProfileTitle(title: ProfileTitle): Promise<string | null> {
  return send("/admin/profile-titles", json("POST", title), 201, "Impossible de créer le titre.");
}

export function updateProfileTitle(key: string, changes: { label?: string; access?: AvatarFrameAccess; position?: number }): Promise<string | null> {
  return send(`/admin/profile-titles/${encodeURIComponent(key)}`, json("PATCH", changes), 204, "Impossible de modifier le titre.");
}

export function setProfileTitleRetired(key: string, retired: boolean): Promise<string | null> {
  return send(`/admin/profile-titles/${encodeURIComponent(key)}/${retired ? "retire" : "restore"}`, { method: "POST" }, 204, "Impossible de changer le titre.");
}

/** Why a title is locked for this member, or null when they may wear it (same rights as the frames). */
export function titleLockReason(access: AvatarFrameAccess, key: string, rights: { admin: boolean; member: boolean; owned: readonly string[] }): string | null {
  switch (access) {
    case "free":
      return null;
    case "members":
      return rights.admin || rights.member ? null : "Réservé aux adhérents";
    case "admins":
      return rights.admin ? null : "Réservé aux admins";
    case "shop":
      return rights.owned.includes(key) ? null : "À acheter en boutique";
  }
}

/** A key from a label: « Chasseur de goals » becomes « chasseur-de-goals », within the 32 characters the API takes. */
export function titleKeyFrom(label: string): string {
  return label
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 32)
    .replace(/-+$/g, "");
}
