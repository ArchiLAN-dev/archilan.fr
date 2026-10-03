import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";
import { AVATAR_FRAMES } from "@/features/community/avatar-frames";
import { BANNER_PRESETS } from "@/features/community/banner-presets";

/** Story 41.7: a cosmetic on sale, as a member sees it. */
export type ShopItem = { id: string; type: "frame" | "banner"; cosmeticKey: string; price: number; availableUntil: string | null; owned: boolean };

export type AdminShopItem = {
  id: string;
  type: "frame" | "banner";
  cosmeticKey: string;
  price: number;
  availableFrom: string | null;
  availableUntil: string | null;
  status: "on_sale" | "upcoming" | "ended" | "retired";
};

export type AdminShop = { items: AdminShopItem[]; sellable: { frame: string[]; banner: string[] } };

export type NewShopItem = { type: "frame" | "banner"; cosmeticKey: string; price: number; availableFrom: string | null; availableUntil: string | null };

const STATUSES = ["on_sale", "upcoming", "ended", "retired"] as const;

function isType(v: unknown): v is "frame" | "banner" {
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
    STATUSES.some((s) => s === v.status)
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

/** The display name of a cosmetic, from the frontend catalog (its key when unknown). */
export function cosmeticLabel(type: "frame" | "banner", key: string): string {
  const catalog: readonly { key: string; label: string }[] = type === "frame" ? AVATAR_FRAMES : BANNER_PRESETS;
  return catalog.find((c) => c.key === key)?.label ?? key;
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

export async function retireShopItem(itemId: string): Promise<string | null> {
  return send(`${env.apiBaseUrl}/admin/shop/items/${itemId}`, { method: "DELETE" }, 204, "Le retrait a échoué.");
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
