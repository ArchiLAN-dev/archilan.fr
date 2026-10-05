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
  /** Story 41.14: the price outside any promotion (`price` is what a purchase costs now). Absent on an older API. */
  regularPrice?: number;
  /** Story 41.14: the running promotion, null when none. Absent on an older API. */
  promotion?: ShopPromotion | null;
};

/** Story 41.14: a promotion a member can use now. */
export type ShopPromotion = { price: number; endsAt: string; percent: number };

export type AdminPromotionStatus = "running" | "upcoming" | "ended";

/** Story 41.14: the promotion set on an item, whatever its state. */
export type AdminShopPromotion = { price: number; startsAt: string | null; endsAt: string; status: AdminPromotionStatus };

export type PromotionTerms = { price: number; startsAt: string | null; endsAt: string | null };

/** Story 41.14: the banner announcing a promotion on the HelloAsso shop. */
export type ShopAnnouncement = { message: string; endsAt: string };

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
  /** Story 41.14: absent on an older API. */
  promotion?: AdminShopPromotion | null;
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

function isPromotion(v: unknown): v is ShopPromotion {
  return typeof v === "object" && v !== null && hasNumberProp(v, "price") && hasStringProp(v, "endsAt") && hasNumberProp(v, "percent");
}

function isAdminPromotion(v: unknown): v is AdminShopPromotion {
  return (
    typeof v === "object" &&
    v !== null &&
    hasNumberProp(v, "price") &&
    hasNullableStringProp(v, "startsAt") &&
    hasStringProp(v, "endsAt") &&
    "status" in v &&
    (v.status === "running" || v.status === "upcoming" || v.status === "ended")
  );
}

/** An optional field: absent and null pass, anything else must match. */
function optional(v: object, key: string, guard: (value: unknown) => boolean): boolean {
  if (!(key in v)) return true;
  const value: unknown = Reflect.get(v, key);
  return value === null || guard(value);
}

function isShopItem(v: unknown): v is ShopItem {
  return (
    typeof v === "object" &&
    v !== null &&
    optional(v, "regularPrice", (p) => typeof p === "number") &&
    optional(v, "promotion", isPromotion) &&
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
    optional(v, "promotion", isAdminPromotion) &&
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

/**
 * Null on success, otherwise the server's message. Story 41.14: sends the price the member agreed to - the server
 * refuses (« Le prix a changé ») rather than charge another one.
 */
export async function buyShopItem(itemId: string, expectedPrice: number): Promise<string | null> {
  return send(
    `${env.apiBaseUrl}/shop/items/${itemId}/buy`,
    { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ expectedPrice }) },
    204,
    "L'achat a échoué.",
  );
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

/** Story 41.14: puts the item on promotion, replacing any previous one. */
export async function setShopItemPromotion(itemId: string, terms: PromotionTerms): Promise<string | null> {
  return send(
    `${env.apiBaseUrl}/admin/shop/items/${itemId}/promotion`,
    { method: "PUT", headers: { "Content-Type": "application/json" }, body: JSON.stringify(terms) },
    204,
    "La promotion n'a pas pu être enregistrée.",
  );
}

export async function endShopItemPromotion(itemId: string): Promise<string | null> {
  return send(`${env.apiBaseUrl}/admin/shop/items/${itemId}/promotion`, { method: "DELETE" }, 204, "La promotion n'a pas pu être retirée.");
}

function isAnnouncement(v: unknown): v is ShopAnnouncement {
  return typeof v === "object" && v !== null && hasStringProp(v, "message") && hasStringProp(v, "endsAt");
}

/** Story 41.14: the HelloAsso shop banner while it runs; null when there is none - or when the read failed. */
export async function fetchShopAnnouncement(path: "shop" | "admin/shop" = "shop"): Promise<ShopAnnouncement | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/${path}/announcement`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return typeof payload === "object" && payload !== null && "data" in payload && isAnnouncement(payload.data) ? payload.data : null;
  } catch {
    return null;
  }
}

export async function postShopAnnouncement(announcement: ShopAnnouncement): Promise<string | null> {
  return send(
    `${env.apiBaseUrl}/admin/shop/announcement`,
    { method: "PUT", headers: { "Content-Type": "application/json" }, body: JSON.stringify(announcement) },
    204,
    "Le bandeau n'a pas pu être enregistré.",
  );
}

export async function takeDownShopAnnouncement(): Promise<string | null> {
  return send(`${env.apiBaseUrl}/admin/shop/announcement`, { method: "DELETE" }, 204, "Le bandeau n'a pas pu être retiré.");
}

/**
 * Story 41.14: how long a promotion has left, in words - days while there are days, then hours, then minutes.
 * `now` is passed in so the render stays pure (AC-HK3).
 */
export function timeLeftLabel(endsAt: string, now: number): string {
  const left = new Date(endsAt).getTime() - now;
  if (Number.isNaN(left) || left <= 0) return "Terminée";
  const minutes = Math.floor(left / 60_000);
  if (minutes < 60) return `Plus que ${Math.max(1, minutes)} min`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `Plus que ${hours} h`;
  return `Plus que ${Math.floor(hours / 24)} j`;
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
