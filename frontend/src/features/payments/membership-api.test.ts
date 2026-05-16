import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { getMembershipCheckoutUrl } from "./membership-api";

const BASE = TEST_API_BASE_URL;

describe("getMembershipCheckoutUrl", () => {
  it("returns checkout URL on success", async () => {
    server.use(
      http.get(`${BASE}/membership/checkout`, () =>
        HttpResponse.json({ data: { checkoutEmbedUrl: "https://checkout.example.com" } }),
      ),
    );
    const result = await getMembershipCheckoutUrl();
    expect(result).toBe("https://checkout.example.com");
  });

  it("returns null on network error", async () => {
    server.use(http.get(`${BASE}/membership/checkout`, () => HttpResponse.error()));
    expect(await getMembershipCheckoutUrl()).toBeNull();
  });

  it("returns null when response fails type guard", async () => {
    server.use(
      http.get(`${BASE}/membership/checkout`, () => HttpResponse.json({ wrong: true })),
    );
    expect(await getMembershipCheckoutUrl()).toBeNull();
  });

  it("returns null on non-OK response", async () => {
    server.use(
      http.get(`${BASE}/membership/checkout`, () => new HttpResponse(null, { status: 500 })),
    );
    expect(await getMembershipCheckoutUrl()).toBeNull();
  });
});
