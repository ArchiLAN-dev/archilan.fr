import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";
import type { AvatarFrameAccess } from "./avatar-frame-catalog";

/** Story 41.27: how a title shines, and the icon before its label. */
export type TitleRarity = "common" | "rare" | "epic" | "legendary";
export type TitleIcon = "crown" | "star" | "sword" | "gem" | "shield" | "flame" | "trophy" | "bolt";

export const TITLE_RARITIES: readonly TitleRarity[] = ["common", "rare", "epic", "legendary"];
export const TITLE_ICONS: readonly TitleIcon[] = ["crown", "star", "sword", "gem", "shield", "flame", "trophy", "bolt"];

export const TITLE_RARITY_LABELS: Record<TitleRarity, string> = { common: "Commun", rare: "Rare", epic: "Épique", legendary: "Légendaire" };
export const TITLE_ICON_LABELS: Record<TitleIcon, string> = {
  crown: "Couronne",
  star: "Étoile",
  sword: "Épée",
  gem: "Gemme",
  shield: "Bouclier",
  flame: "Flamme",
  trophy: "Trophée",
  bolt: "Éclair",
};

/** Story 41.22: a profile title the admins write, worn under the name. Story 41.27: its rarity and icon. */
export type ProfileTitle = { key: string; label: string; access: AvatarFrameAccess; rarity: TitleRarity; icon: TitleIcon | null };

/** Story 41.27: a title as a profile or a card wears it. */
export type TitleBadge = { label: string; rarity: TitleRarity; icon: TitleIcon | null; access: AvatarFrameAccess };

/** Story 41.27: how a title is obtained, for its tooltip. */
export function titleOrigin(access: AvatarFrameAccess): string {
  return { free: "Ouvert à tous", members: "Réservé aux adhérents", admins: "Réservé aux admins", shop: "En boutique" }[access];
}

export function badgeOf(title: Pick<ProfileTitle, "label" | "rarity" | "icon" | "access">): TitleBadge {
  return { label: title.label, rarity: title.rarity, icon: title.icon, access: title.access };
}

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

function isRarity(v: unknown): v is TitleRarity {
  return TITLE_RARITIES.some((rarity) => rarity === v);
}

function isIcon(v: unknown): v is TitleIcon | null {
  return v === null || TITLE_ICONS.some((icon) => icon === v);
}

function isTitle(v: unknown): v is ProfileTitle {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "key") &&
    hasStringProp(v, "label") &&
    hasStringProp(v, "access") &&
    ACCESSES.some((a) => a === v.access) &&
    "rarity" in v &&
    isRarity(v.rarity) &&
    "icon" in v &&
    isIcon(v.icon)
  );
}

/** Story 41.27: the title badge an API answer carries (profile, cards). */
export function isTitleBadge(v: unknown): v is TitleBadge {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "label") &&
    "rarity" in v &&
    isRarity(v.rarity) &&
    "icon" in v &&
    isIcon(v.icon) &&
    hasStringProp(v, "access") &&
    ACCESSES.some((a) => a === v.access)
  );
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

export function updateProfileTitle(key: string, changes: { label?: string; access?: AvatarFrameAccess; position?: number; rarity?: TitleRarity; icon?: TitleIcon | "" }): Promise<string | null> {
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
