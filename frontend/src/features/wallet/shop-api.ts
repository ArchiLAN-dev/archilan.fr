import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";
import { AVATAR_FRAMES } from "@/features/community/avatar-frames";
import { BANNER_PRESETS } from "@/features/community/banner-presets";

export type CosmeticType = "frame" | "banner";

/** Story 41.7: a cosmetic on sale, as a member (or, story 41.12, a visitor) sees it. */
export type ShopItem = {
  id: string;
  type: CosmeticType;
  cosmeticKey: string;
  price: number;
  availableUntil: string | null;
  /** Story 41.12: when it was put on sale, for the « Nouveau » badge. */
  listedAt: string;
  owned: boolean;
};

export type AdminShopStatus = "on_sale" | "upcoming" | "ended" | "paused";

export type AdminShopItem = {
  id: string;
  type: CosmeticType;
  cosmeticKey: string;
  price: number;
  availableFrom: string | null;
  availableUntil: string | null;
  status: AdminShopStatus;
  /** Story 41.12: purchases of this item, and the pelles they brought. */
  sales: number;
  pelles: number;
};

export type AdminShop = { items: AdminShopItem[]; sellable: { frame: string[]; banner: string[] } };

export type NewShopItem = { type: CosmeticType; cosmeticKey: string; price: number; availableFrom: string | null; availableUntil: string | null };

export type ShopItemTerms = { price: number; availableFrom: string | null; availableUntil: string | null };

const STATUSES: readonly AdminShopStatus[] = ["on_sale", "upcoming", "ended", "paused"];

/** Story 41.12: an item listed less than this long ago is « Nouveau ». */
export const NEW_ITEM_DAYS = 14;

function isType(v: unknown): v is CosmeticType {
  return v === "frame" || v === "banner";
}

function isShopItem(v: unknown): v is ShopItem {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "id") &&
    "type" in v &&
    isType(v.type) &&
    hasStringProp(v, "cosmeticKey") &&
    hasNumberProp(v, "price") &&
    hasNullableStringProp(v, "availableUntil") &&
    hasStringProp(v, "listedAt") &&
    hasBooleanProp(v, "owned")
  );
}

function isAdminShopItem(v: unknown): v is AdminShopItem {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "id") &&
    "type" in v &&
    isType(v.type) &&
    hasStringProp(v, "cosmeticKey") &&
    hasNumberProp(v, "price") &&
    hasNullableStringProp(v, "availableFrom") &&
    hasNullableStringProp(v, "availableUntil") &&
    hasStringProp(v, "status") &&
    STATUSES.some((s) => s === v.status) &&
    hasNumberProp(v, "sales") &&
    hasNumberProp(v, "pelles")
  );
}

function isKeyList(v: unknown): v is string[] {
  return Array.isArray(v) && v.every((k: unknown) => typeof k === "string");
}

export function isAdminShop(v: unknown): v is AdminShop {
  if (typeof v !== "object" || v === null || !("items" in v) || !Array.isArray(v.items) || !v.items.every(isAdminShopItem)) return false;
  if (!("sellable" in v) || typeof v.sellable !== "object" || v.sellable === null) return false;
  const sellable = v.sellable;
  return "frame" in sellable && isKeyList(sellable.frame) && "banner" in sellable && isKeyList(sellable.banner);
}

/**
 * The display name of a cosmetic: the admin catalogs' name first (stories 41.10 and 41.11, which may rename a code
 * cosmetic), then the code catalogs, then its key.
 */
export function cosmeticLabel(type: CosmeticType, key: string, catalogs: readonly { key: string; label: string }[] = []): string {
  const code: readonly { key: string; label: string }[] = type === "frame" ? AVATAR_FRAMES : BANNER_PRESETS;
  return catalogs.find((c) => c.key === key)?.label ?? code.find((c) => c.key === key)?.label ?? key;
}

/** Whether an item went on sale less than NEW_ITEM_DAYS ago. */
export function isNewItem(listedAt: string, now: Date = new Date()): boolean {
  const listed = new Date(listedAt).getTime();
  return !Number.isNaN(listed) && now.getTime() - listed < NEW_ITEM_DAYS * 24 * 3600 * 1000;
}

export async function fetchShop(): Promise<ShopItem[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/shop`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return typeof payload === "object" && payload !== null && "items" in payload && Array.isArray(payload.items) && payload.items.every(isShopItem)
      ? payload.items
      : null;
  } catch {
    return null;
  }
}

export async function fetchAdminShop(): Promise<AdminShop | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/shop/items`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isAdminShop(payload) ? payload : null;
  } catch {
    return null;
  }
}

/** Null on success, otherwise the server's message. */
export async function buyShopItem(itemId: string): Promise<string | null> {
  return send(`${env.apiBaseUrl}/shop/items/${itemId}/buy`, { method: "POST" }, 204, "L'achat a échoué.");
}

export async function listShopItem(item: NewShopItem): Promise<string | null> {
  return send(
    `${env.apiBaseUrl}/admin/shop/items`,
    { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(item) },
    201,
    "La mise en vente a échoué.",
  );
}

export async function editShopItem(itemId: string, terms: ShopItemTerms): Promise<string | null> {
  return send(
    `${env.apiBaseUrl}/admin/shop/items/${itemId}`,
    { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify(terms) },
    204,
    "La modification a échoué.",
  );
}

export async function setShopItemPaused(itemId: string, paused: boolean): Promise<string | null> {
  return send(`${env.apiBaseUrl}/admin/shop/items/${itemId}/${paused ? "pause" : "resume"}`, { method: "POST" }, 204, "L'opération a échoué.");
}

/** Deletes the item for good; its buyers keep the cosmetic. */
export async function deleteShopItem(itemId: string): Promise<string | null> {
  return send(`${env.apiBaseUrl}/admin/shop/items/${itemId}`, { method: "DELETE" }, 204, "La suppression a échoué.");
}

async function send(url: string, init: RequestInit, expected: number, fallback: string): Promise<string | null> {
  try {
    const res = await apiFetch(url, init);
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
