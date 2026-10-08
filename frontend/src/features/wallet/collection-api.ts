import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasNumberProp, hasStringProp } from "@/lib/type-guards";
import { COSMETIC_REWARD_TYPES, type CosmeticRewardType } from "@/features/community/cosmetic-reward-picker";
import { TITLE_ICONS, TITLE_RARITIES, type TitleIcon, type TitleRarity } from "@/features/community/profile-title-catalog";
import type { AvatarFrameAccess } from "@/features/community/avatar-frame-catalog";

/** Story 41.29: how to get a cosmetic the member has not. */
export type CollectionUnlock = { kind: "achievement" | "quest" | "shop" | "members" | "admins" | "reward"; label: string; detail: string | null; price: number | null };

/** Story 41.29: one cosmetic of « Ma collection ». */
export type CollectionItem = {
  type: CosmeticRewardType;
  key: string;
  label: string;
  access: AvatarFrameAccess;
  rarity: TitleRarity | null;
  icon: TitleIcon | null;
  status: "owned" | "available" | "locked";
  origin: { source: string; label: string | null; acquiredAt: string } | null;
  unlock: CollectionUnlock[];
};

export type Collection = { items: CollectionItem[]; owned: number; total: number };

export const COLLECTION_QUERY_KEY = ["my-collection"] as const;

const ACCESSES: readonly AvatarFrameAccess[] = ["free", "members", "admins", "shop", "reward"];
const STATUSES: readonly CollectionItem["status"][] = ["owned", "available", "locked"];
const UNLOCK_KINDS: readonly CollectionUnlock["kind"][] = ["achievement", "quest", "shop", "members", "admins", "reward"];

function isObject(v: unknown): v is object {
  return typeof v === "object" && v !== null;
}

function isNullableString(v: unknown): v is string | null {
  return v === null || typeof v === "string";
}

function isUnlock(v: unknown): v is CollectionUnlock {
  return (
    isObject(v) &&
    "kind" in v &&
    UNLOCK_KINDS.some((kind) => kind === v.kind) &&
    hasStringProp(v, "label") &&
    "detail" in v &&
    isNullableString(v.detail) &&
    "price" in v &&
    (v.price === null || typeof v.price === "number")
  );
}

function isOrigin(v: unknown): v is CollectionItem["origin"] {
  return v === null || (isObject(v) && hasStringProp(v, "source") && "label" in v && isNullableString(v.label) && hasStringProp(v, "acquiredAt"));
}

function isItem(v: unknown): v is CollectionItem {
  return (
    isObject(v) &&
    "type" in v &&
    COSMETIC_REWARD_TYPES.some((type) => type === v.type) &&
    hasStringProp(v, "key") &&
    hasStringProp(v, "label") &&
    "access" in v &&
    ACCESSES.some((access) => access === v.access) &&
    "rarity" in v &&
    (v.rarity === null || TITLE_RARITIES.some((rarity) => rarity === v.rarity)) &&
    "icon" in v &&
    (v.icon === null || TITLE_ICONS.some((icon) => icon === v.icon)) &&
    "status" in v &&
    STATUSES.some((status) => status === v.status) &&
    "origin" in v &&
    isOrigin(v.origin) &&
    "unlock" in v &&
    Array.isArray(v.unlock) &&
    v.unlock.every(isUnlock)
  );
}

export function isCollection(v: unknown): v is Collection {
  return isObject(v) && hasNumberProp(v, "owned") && hasNumberProp(v, "total") && "items" in v && Array.isArray(v.items) && v.items.every(isItem);
}

export async function fetchMyCollection(): Promise<Collection | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/me/collection`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isCollection(payload) ? payload : null;
  } catch {
    return null;
  }
}

/** « Succès « Un jour sans fin » », « Acheté en boutique » - where an owned cosmetic came from. */
export function originText(origin: NonNullable<CollectionItem["origin"]>): string {
  if (origin.source === "achievement") return `Succès « ${origin.label ?? "?"} »`;
  if (origin.source === "quest") return `Quête « ${origin.label ?? "?"} »`;
  if (origin.source === "collection") return `Collection « ${origin.label ?? "?"} »`;
  return "Acheté en boutique";
}
