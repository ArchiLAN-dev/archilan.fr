import { isNewItem, type CosmeticType, type ShopItem } from "./shop-api";

/** Story 41.31: a section of the shop, by the kind of cosmetic, as it reads in the address (`?rayon=cadres`). */
export type ShelfKey = "cadres" | "bannieres" | "titres" | "couleurs";

export const SHELVES: readonly { key: ShelfKey; type: CosmeticType; label: string; dense: boolean }[] = [
  { key: "cadres", type: "frame", label: "Cadres", dense: false },
  { key: "bannieres", type: "banner", label: "Bannières", dense: false },
  { key: "titres", type: "title", label: "Titres", dense: true },
  { key: "couleurs", type: "color", label: "Couleurs de pseudo", dense: true },
];

export type Shelf = (typeof SHELVES)[number] & { items: ShopItem[] };

/** The section an address names, or null (« Tout ») for none or an unknown one. */
export function shelfFromParam(value: string | null | undefined): ShelfKey | null {
  return SHELVES.find((shelf) => shelf.key === value)?.key ?? null;
}

export function shelfHref(key: ShelfKey | null): string {
  return key === null ? "/boutique" : `/boutique?rayon=${key}`;
}

/** New items and promotions first, then the shop's order; what the member owns last. */
function rank(item: ShopItem, now: Date): number {
  if (item.owned) return 2;
  return isNewItem(item.listedAt, now) || item.promotion != null ? 0 : 1;
}

/**
 * The shop in its sections, in their fixed order: each with its items sorted (stable, so the shop's order holds
 * within a rank), only the promotions under « En promo », and no empty section.
 */
export function shopShelves(items: ShopItem[], options: { promoOnly: boolean; now: Date }): Shelf[] {
  const kept = options.promoOnly ? items.filter((item) => item.promotion != null) : items;
  return SHELVES.map((shelf) => ({
    ...shelf,
    items: kept.filter((item) => item.type === shelf.type).sort((a, b) => rank(a, options.now) - rank(b, options.now)),
  })).filter((shelf) => shelf.items.length > 0);
}
