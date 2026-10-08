import type { ReactElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import type { ShopItem } from "./shop-api";
import { ShopView } from "./shop-page";
import { shelfFromParam, shelfHref, shopShelves } from "./shop-shelves";

const NOW = new Date("2026-10-08T12:00:00+00:00");
const OLD = "2026-06-01T10:00:00+00:00";
const RECENT = "2026-10-05T10:00:00+00:00";

const item = (id: string, type: ShopItem["type"], over: Partial<ShopItem> = {}): ShopItem => ({
  id, type, cosmeticKey: id, price: 50, availableUntil: null, listedAt: OLD, owned: false, ...over,
});
const promo = { price: 25, endsAt: "2026-10-20T00:00:00+00:00", percent: 50 };

const items = [
  item("owned-frame", "frame", { owned: true }),
  item("old-frame", "frame"),
  item("title-a", "title"),
  item("new-frame", "frame", { listedAt: RECENT }),
  item("promo-frame", "frame", { promotion: promo }),
  item("emerald", "color", { promotion: promo }),
];

function render(element: ReactElement): string {
  const client = new QueryClient();
  client.setQueryData(["avatar-frame-catalog"], []);
  client.setQueryData(["profile-banner-catalog"], []);
  return renderToStaticMarkup(<QueryClientProvider client={client}>{element}</QueryClientProvider>);
}

const noop = () => Promise.resolve(null);

/** Story 41.31: the shop in sections by kind. */
describe("shop shelves", () => {
  test("sections in a fixed order, empty ones dropped, new and promoted first, owned last", () => {
    const shelves = shopShelves(items, { promoOnly: false, now: NOW });
    expect(shelves.map((s) => s.key)).toEqual(["cadres", "titres", "couleurs"]);
    expect(shelves[0]?.items.map((i) => i.id)).toEqual(["new-frame", "promo-frame", "old-frame", "owned-frame"]);
  });

  test("« En promo » keeps only the promotions, and the sections that have some", () => {
    const shelves = shopShelves(items, { promoOnly: true, now: NOW });
    expect(shelves.map((s) => [s.key, s.items.map((i) => i.id)])).toEqual([
      ["cadres", ["promo-frame"]],
      ["couleurs", ["emerald"]],
    ]);
  });

  test("the address names a section; an unknown one falls back to everything", () => {
    expect(shelfFromParam("titres")).toBe("titres");
    expect(shelfFromParam("chapeaux")).toBeNull();
    expect(shelfFromParam(undefined)).toBeNull();
    expect(shelfHref("cadres")).toBe("/boutique?rayon=cadres");
    expect(shelfHref(null)).toBe("/boutique");
  });

  test("the page shows a heading per section, chips with their counts, and only the chosen section", () => {
    const all = render(<ShopView gold={100} items={items} now={NOW} onBuy={noop} shopper={null} />);
    expect(all).toContain('id="rayon-cadres"');
    expect(all).toContain('id="rayon-couleurs"');
    expect(all).toContain('href="/boutique?rayon=titres"');
    expect(all).toContain('href="/boutique?rayon=cadres">Cadres<span class="tabular-nums text-xs text-muted-foreground">4</span>');

    const titles = render(<ShopView gold={100} items={items} now={NOW} onBuy={noop} shelf="titres" shopper={null} />);
    expect(titles).toContain('id="rayon-titres"');
    expect(titles).not.toContain('id="rayon-cadres"');

    const empty = render(<ShopView gold={100} items={items} now={NOW} onBuy={noop} shelf="bannieres" shopper={null} />);
    expect(empty).toContain("Rien dans ce rayon pour l&#x27;instant");
  });
});
