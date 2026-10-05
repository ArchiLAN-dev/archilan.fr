import type { ReactElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { getDonationCheckoutUrl } from "./donation-api";
import { AnnouncementView } from "./shop-announcement-banner";
import { SupportArchilan } from "./support-archilan";

/** The articles section reads the HelloAsso banner through TanStack Query (story 41.14). */
function render(element: ReactElement): string {
  return renderToStaticMarkup(<QueryClientProvider client={new QueryClient()}>{element}</QueryClientProvider>);
}

/** Story 41.13: the « Soutenir ArchiLAN » tab of the shop. */
describe("support ArchiLAN", () => {
  test("three anchored sections, in order, with a summary", () => {
    const html = render(<SupportArchilan forms={{ membership: "https://ha.test/m", donation: "https://ha.test/d", shop: "https://ha.test/s" }} />);

    expect(html.indexOf('id="adhesion"')).toBeLessThan(html.indexOf('id="don"'));
    expect(html.indexOf('id="don"')).toBeLessThan(html.indexOf('id="articles"'));
    expect(html).toContain('href="#don"');
    expect(html).toContain("Payer la cotisation");
    expect(html).toContain("Accéder au formulaire de don");
    expect(html).toContain("Commander");
  });

  test("a form not configured says so", () => {
    const html = render(<SupportArchilan forms={{ membership: null, donation: null, shop: null }} />);

    expect(html).toContain("Le formulaire de don n&#x27;est pas disponible pour le moment.");
    expect(html).toContain("Le formulaire de cotisation n&#x27;est pas disponible pour le moment.");
    expect(html).not.toContain("Accéder au formulaire de don");
  });

  test("the HelloAsso banner says the promotion and its time left (story 41.14)", () => {
    const html = renderToStaticMarkup(<AnnouncementView announcement={{ message: "Sweats à -20 % !", endsAt: "2026-10-07T12:00:00+00:00" }} now={new Date("2026-10-04T12:00:00+00:00").getTime()} />);

    expect(html).toContain("Sweats à -20 % !");
    expect(html).toContain("Plus que 3 j");
    expect(html).toContain('role="status"');
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
