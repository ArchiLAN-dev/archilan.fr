import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { getDonationCheckoutUrl } from "./donation-api";
import { SupportArchilan } from "./support-archilan";

/** Story 41.13: the « Soutenir ArchiLAN » tab of the shop. */
describe("support ArchiLAN", () => {
  test("three anchored sections, in order, with a summary", () => {
    const html = renderToStaticMarkup(<SupportArchilan forms={{ membership: "https://ha.test/m", donation: "https://ha.test/d", shop: "https://ha.test/s" }} />);

    expect(html.indexOf('id="adhesion"')).toBeLessThan(html.indexOf('id="don"'));
    expect(html.indexOf('id="don"')).toBeLessThan(html.indexOf('id="articles"'));
    expect(html).toContain('href="#don"');
    expect(html).toContain("Payer la cotisation");
    expect(html).toContain("Accéder au formulaire de don");
    expect(html).toContain("Commander");
  });

  test("a form not configured says so", () => {
    const html = renderToStaticMarkup(<SupportArchilan forms={{ membership: null, donation: null, shop: null }} />);

    expect(html).toContain("Le formulaire de don n&#x27;est pas disponible pour le moment.");
    expect(html).toContain("Le formulaire de cotisation n&#x27;est pas disponible pour le moment.");
    expect(html).not.toContain("Accéder au formulaire de don");
  });

  test("API: reads the donation form", async () => {
    server.use(http.get(`${TEST_API_BASE_URL}/donation/checkout`, () => HttpResponse.json({ data: { checkoutEmbedUrl: "https://ha.test/d" }, meta: [] })));
    expect(await getDonationCheckoutUrl()).toBe("https://ha.test/d");

    server.use(http.get(`${TEST_API_BASE_URL}/donation/checkout`, () => HttpResponse.json({ data: { checkoutEmbedUrl: null }, meta: [] })));
    expect(await getDonationCheckoutUrl()).toBeNull();

    server.use(http.get(`${TEST_API_BASE_URL}/donation/checkout`, () => HttpResponse.error()));
    expect(await getDonationCheckoutUrl()).toBeNull();
  });
});
