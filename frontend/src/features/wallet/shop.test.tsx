import type { ReactElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { AdminShopView, isoOrNull, localOrEmpty } from "./admin-shop-page";
import {
  buyShopItem,
  cosmeticLabel,
  deleteShopItem,
  editShopItem,
  fetchAdminShop,
  fetchShop,
  isNewItem,
  listShopItem,
  setShopItemPaused,
  type AdminShop,
  type ShopItem,
} from "./shop-api";
import { ShopView, type ShopShopper } from "./shop-page";
import { pelleReasonLabel } from "./wallet-api";

const BASE = TEST_API_BASE_URL;
const noop = () => Promise.resolve(null);
const NOW = new Date("2026-10-04T12:00:00+00:00");

function render(element: ReactElement): string {
  // The catalogs are seeded: the cards read the admin's names from them.
  const client = new QueryClient();
  client.setQueryData(["avatar-frame-catalog"], [{ key: "comet", label: "Comète", access: "shop", builtIn: false, video: null }]);
  client.setQueryData(["profile-banner-catalog"], []);
  return renderToStaticMarkup(<QueryClientProvider client={client}>{element}</QueryClientProvider>);
}

const shopper: ShopShopper = {
  avatarUrl: null,
  name: "Member",
  framing: null,
  frame: null,
  banner: { presetKey: "default", imageUrl: null, framing: { x: 50, y: 50, zoom: 100 }, overlay: 50 },
};
const comet: ShopItem = { id: "i1", type: "frame", cosmeticKey: "comet", price: 80, availableUntil: "2026-12-31T23:30:00+00:00", listedAt: "2026-10-01T10:00:00+00:00", owned: false };
const sunset: ShopItem = { id: "i2", type: "banner", cosmeticKey: "sunset", price: 50, availableUntil: null, listedAt: "2026-06-01T10:00:00+00:00", owned: true };
const handlers = { onList: noop, onEdit: noop, onPause: noop, onDelete: noop };

/** Stories 41.7 and 41.12: the cosmetics shop. */
describe("shop", () => {
  test("an empty shop says why", () => {
    expect(render(<ShopView gold={100} items={[]} now={NOW} onBuy={noop} shopper={shopper} />)).toContain("La boutique est vide pour l&#x27;instant");
  });

  test("a card shows the item, its real name, a season's end, and what is new or owned", () => {
    const html = render(<ShopView gold={100} items={[comet, sunset]} now={NOW} onBuy={noop} shopper={shopper} />);

    expect(html).toContain("Comète");
    expect(html).toContain("Coucher de soleil");
    expect(html).toContain("jusqu&#x27;au 1 janvier");
    expect(html).toContain("Nouveau");
    expect(html).toContain("Possédé");
    expect(html).toContain("Le porter");
    expect(html).toContain("Acheter");
    expect(html).toContain("Essayer");
  });

  test("the balance shows, and an item too dear says what is missing", () => {
    const html = render(<ShopView gold={30} items={[comet]} now={NOW} onBuy={noop} shopper={shopper} />);

    expect(html).toContain("Ton solde");
    expect(html).toContain("Il te manque 50 pelles");
    expect(html).not.toContain("Acheter");
  });

  test("a visitor sees the shop window and is asked to sign in", () => {
    const html = render(<ShopView gold={null} items={[comet]} now={NOW} onBuy={noop} shopper={null} />);

    expect(html).toContain("Connecte-toi pour acheter");
    expect(html).toContain("returnTo=/boutique");
    expect(html).not.toContain("Ton solde");
  });

  test("the admin page explains how to get something to sell", () => {
    const shop: AdminShop = { items: [], sellable: { frame: [], banner: [] } };
    const html = render(<AdminShopView shop={shop} {...handlers} />);

    expect(html).toContain("Aucun cosmétique à vendre pour l&#x27;instant");
    expect(html).not.toContain("Mettre en vente");
  });

  test("the admin page sums the sales and shows each item with its state and actions", () => {
    const shop: AdminShop = {
      items: [
        { id: "i1", type: "frame", cosmeticKey: "comet", price: 80, availableFrom: null, availableUntil: null, status: "paused", sales: 2, pelles: 160 },
        { id: "i2", type: "banner", cosmeticKey: "sunset", price: 40, availableFrom: null, availableUntil: null, status: "on_sale", sales: 1, pelles: 40 },
      ],
      sellable: { frame: ["comet"], banner: [] },
    };
    const html = render(<AdminShopView shop={shop} {...handlers} />);

    expect(html).toContain("Mettre en vente");
    expect(html).toContain("Comète");
    expect(html).toContain("En pause");
    expect(html).toContain("Reprendre");
    expect(html).toContain("Mettre en pause");
    expect(html).toContain("Supprimer");
    expect(html).toContain("2 ventes");
    expect(html).toContain("Tous (2)");
    expect(html).toMatch(/Ventes<\/dt><dd[^>]*>3</);
  });

  test("helpers: labels, dates, novelty, reason", () => {
    expect(cosmeticLabel("frame", "gold")).toBe("Or");
    expect(cosmeticLabel("frame", "comet", [{ key: "comet", label: "Comète" }])).toBe("Comète");
    expect(cosmeticLabel("frame", "unknown")).toBe("unknown");
    expect(isoOrNull("")).toBeNull();
    expect(isoOrNull("2026-12-01T10:00")).toMatch(/^2026-12-01T\d{2}:00:00\+00:00$/);
    expect(localOrEmpty(null)).toBe("");
    expect(isoOrNull(localOrEmpty("2026-12-01T10:00:00+00:00"))).toBe("2026-12-01T10:00:00+00:00");
    expect(isNewItem("2026-10-01T10:00:00+00:00", NOW)).toBe(true);
    expect(isNewItem("2026-06-01T10:00:00+00:00", NOW)).toBe(false);
    expect(pelleReasonLabel("shop_purchase")).toBe("Achat en boutique");
  });

  test("API: reads the shop, buys, lists, edits, pauses, deletes, and relays refusals", async () => {
    const calls: string[] = [];
    server.use(
      http.get(`${BASE}/shop`, () => HttpResponse.json({ items: [comet] })),
      http.post(`${BASE}/shop/items/i1/buy`, () => new HttpResponse(null, { status: 204 })),
      http.get(`${BASE}/admin/shop/items`, () => HttpResponse.json({ items: [], sellable: { frame: [], banner: [] } })),
      http.post(`${BASE}/admin/shop/items`, () => HttpResponse.json({ id: "i2" }, { status: 201 })),
      http.patch(`${BASE}/admin/shop/items/i1`, async ({ request }) => {
        calls.push(`edit ${JSON.stringify(await request.json())}`);
        return new HttpResponse(null, { status: 204 });
      }),
      http.post(`${BASE}/admin/shop/items/i1/pause`, () => {
        calls.push("pause");
        return new HttpResponse(null, { status: 204 });
      }),
      http.post(`${BASE}/admin/shop/items/i1/resume`, () => {
        calls.push("resume");
        return new HttpResponse(null, { status: 204 });
      }),
      http.delete(`${BASE}/admin/shop/items/i1`, () => {
        calls.push("delete");
        return new HttpResponse(null, { status: 204 });
      }),
    );

    expect(await fetchShop()).toEqual([comet]);
    expect(await buyShopItem("i1")).toBeNull();
    expect(await fetchAdminShop()).toEqual({ items: [], sellable: { frame: [], banner: [] } });
    expect(await listShopItem({ type: "frame", cosmeticKey: "comet", price: 80, availableFrom: null, availableUntil: null })).toBeNull();
    expect(await editShopItem("i1", { price: 25, availableFrom: null, availableUntil: null })).toBeNull();
    expect(await setShopItemPaused("i1", true)).toBeNull();
    expect(await setShopItemPaused("i1", false)).toBeNull();
    expect(await deleteShopItem("i1")).toBeNull();
    expect(calls).toEqual(['edit {"price":25,"availableFrom":null,"availableUntil":null}', "pause", "resume", "delete"]);

    server.use(
      http.post(`${BASE}/shop/items/i1/buy`, () =>
        HttpResponse.json({ error: { code: "already_owned", message: "Tu possèdes déjà cet article.", details: [] } }, { status: 409 }),
      ),
    );
    expect(await buyShopItem("i1")).toBe("Tu possèdes déjà cet article.");
  });
});
