import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { AdminShopView, isoOrNull } from "./admin-shop-page";
import { buyShopItem, cosmeticLabel, fetchAdminShop, fetchShop, listShopItem, type AdminShop } from "./shop-api";
import { ShopView } from "./shop-page";
import { pelleReasonLabel } from "./wallet-api";

const BASE = TEST_API_BASE_URL;
const noop = () => Promise.resolve(null);

/** Story 41.7: the cosmetics shop. */
describe("shop", () => {
  test("an empty shop says why", () => {
    const html = renderToStaticMarkup(<ShopView items={[]} onBuy={noop} />);

    expect(html).toContain("La boutique est vide pour l&#x27;instant");
  });

  test("an item shows its price, or that it is owned, and a season's end", () => {
    const html = renderToStaticMarkup(
      <ShopView
        items={[
          { id: "i1", type: "frame", cosmeticKey: "comet", price: 80, availableUntil: "2026-12-31T23:30:00+00:00", owned: false },
          { id: "i2", type: "banner", cosmeticKey: "sunset", price: 50, availableUntil: null, owned: true },
        ]}
        onBuy={noop}
      />,
    );

    expect(html).toContain("comet");
    expect(html).toContain("jusqu&#x27;au 1 janvier");
    expect(html).toContain("80");
    expect(html).toContain("Possédé");
    expect(html).toContain("Coucher de soleil");
  });

  test("the admin page explains that nothing can be sold until members draw shop cosmetics", () => {
    const shop: AdminShop = { items: [], sellable: { frame: [], banner: [] } };
    const html = renderToStaticMarkup(<AdminShopView onList={noop} onRetire={noop} shop={shop} />);

    expect(html).toContain("Aucun cosmétique de boutique dans le catalogue");
    expect(html).not.toContain("Mettre en vente");
  });

  test("the admin page lists the items with their state, and the form when something is sellable", () => {
    const shop: AdminShop = {
      items: [{ id: "i1", type: "frame", cosmeticKey: "comet", price: 80, availableFrom: null, availableUntil: null, status: "retired" }],
      sellable: { frame: ["comet"], banner: [] },
    };
    const html = renderToStaticMarkup(<AdminShopView onList={noop} onRetire={noop} shop={shop} />);

    expect(html).toContain("Mettre en vente");
    expect(html).toContain("Retiré");
    expect(html).not.toContain(">Retirer<");
  });

  test("helpers: labels, dates, reason", () => {
    expect(cosmeticLabel("frame", "gold")).toBe("Or");
    expect(cosmeticLabel("frame", "unknown")).toBe("unknown");
    expect(isoOrNull("")).toBeNull();
    expect(isoOrNull("2026-12-01T10:00")).toMatch(/^2026-12-01T\d{2}:00:00\+00:00$/);
    expect(pelleReasonLabel("shop_purchase")).toBe("Achat en boutique");
  });

  test("API: reads the shop, buys, lists, and relays refusals", async () => {
    let listed: unknown = null;
    server.use(
      http.get(`${BASE}/shop`, () => HttpResponse.json({ items: [{ id: "i1", type: "frame", cosmeticKey: "comet", price: 80, availableUntil: null, owned: false }] })),
      http.post(`${BASE}/shop/items/i1/buy`, () => new HttpResponse(null, { status: 204 })),
      http.get(`${BASE}/admin/shop/items`, () => HttpResponse.json({ items: [], sellable: { frame: [], banner: [] } })),
      http.post(`${BASE}/admin/shop/items`, async ({ request }) => {
        listed = await request.json();
        return HttpResponse.json({ id: "i2" }, { status: 201 });
      }),
    );

    expect(await fetchShop()).toHaveLength(1);
    expect(await buyShopItem("i1")).toBeNull();
    expect(await fetchAdminShop()).toEqual({ items: [], sellable: { frame: [], banner: [] } });
    expect(await listShopItem({ type: "frame", cosmeticKey: "comet", price: 80, availableFrom: null, availableUntil: null })).toBeNull();
    expect(listed).toEqual({ type: "frame", cosmeticKey: "comet", price: 80, availableFrom: null, availableUntil: null });

    server.use(
      http.post(`${BASE}/shop/items/i1/buy`, () =>
        HttpResponse.json({ error: { code: "already_owned", message: "Tu possèdes déjà cet article.", details: [] } }, { status: 409 }),
      ),
    );
    expect(await buyShopItem("i1")).toBe("Tu possèdes déjà cet article.");
  });
});
