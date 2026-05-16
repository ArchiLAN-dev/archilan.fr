import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { getShopCheckoutUrl } from "./shop-api";

const BASE = TEST_API_BASE_URL;

describe("getShopCheckoutUrl", () => {
  it("returns checkout URL on success", async () => {
    server.use(
      http.get(`${BASE}/shop/checkout`, () =>
        HttpResponse.json({ data: { checkoutEmbedUrl: "https://shop.example.com" } }),
      ),
    );
    const result = await getShopCheckoutUrl();
    expect(result).toBe("https://shop.example.com");
  });

  it("returns null when checkoutEmbedUrl is null", async () => {
    server.use(
      http.get(`${BASE}/shop/checkout`, () =>
        HttpResponse.json({ data: { checkoutEmbedUrl: null } }),
      ),
    );
    expect(await getShopCheckoutUrl()).toBeNull();
  });

  it("returns null on network error", async () => {
    server.use(http.get(`${BASE}/shop/checkout`, () => HttpResponse.error()));
    expect(await getShopCheckoutUrl()).toBeNull();
  });

  it("returns null when response fails type guard", async () => {
    server.use(
      http.get(`${BASE}/shop/checkout`, () => HttpResponse.json({ wrong: true })),
    );
    expect(await getShopCheckoutUrl()).toBeNull();
  });

  it("returns null on non-OK response", async () => {
    server.use(
      http.get(`${BASE}/shop/checkout`, () => new HttpResponse(null, { status: 500 })),
    );
    expect(await getShopCheckoutUrl()).toBeNull();
  });
});
