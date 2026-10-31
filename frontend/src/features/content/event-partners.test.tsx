import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";

import {
  addEventPartner,
  EventPartnersEditor,
  MAX_EVENT_PARTNERS,
  moveEventPartner,
  removeEventPartner,
  withMention,
} from "@/features/admin/admin-event-partners";
import { getPublicEvent } from "@/features/events/public-events-api";
import { EventPartners } from "./partner-card";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { isEventPartnerRef, joinEventPartners, saveEventPartners, type AdminPartner, type EventPartnerRef, type Partner } from "./partners-api";

const BASE = TEST_API_BASE_URL;

function partnerNamed(id: string, name: string): Partner {
  return { id, name, description: `${name}, la description.`, url: `https://${id}.example`, logoUrl: `http://minio.test/${id}.png` };
}

const ludo = partnerNamed("p1", "Ludothèque");
const shop = partnerNamed("p2", "Boutique");

const publicEvent = {
  id: "evt-1",
  title: "LAN",
  description: "desc",
  coverImageUrl: null,
  photoGallery: [],
  status: "published",
  startsAt: "2026-11-13T18:00:00+00:00",
  endsAt: "2026-11-15T18:00:00+00:00",
  venue: "Clermont",
  capacity: 40,
  confirmedRegistrations: 0,
  registrationOpensAt: "2026-10-01T00:00:00+00:00",
  registrationClosesAt: "2026-11-12T00:00:00+00:00",
  isPublic: true,
  hasPrivateAccessPassword: false,
  vodUrl: null,
  recapPostSlug: null,
  hasRecap: false,
  checkoutEmbedUrl: null,
  checkoutUnavailable: false,
};

/** Story 45.3: the partners thanked on an event. */
describe("event partners", () => {
  it("keeps the event's order and only the visible partners", () => {
    const refs: EventPartnerRef[] = [
      { partnerId: "p2", mention: "Lots offerts" },
      { partnerId: "gone", mention: null },
      { partnerId: "p1", mention: null },
    ];

    expect(joinEventPartners(refs, [ludo, shop])).toEqual([
      { partner: shop, mention: "Lots offerts" },
      { partner: ludo, mention: null },
    ]);
  });

  it("guards a reference", () => {
    expect(isEventPartnerRef({ partnerId: "p1", mention: null })).toBe(true);
    expect(isEventPartnerRef({ partnerId: "p1", mention: "Merci" })).toBe(true);
    expect(isEventPartnerRef({ partnerId: "p1" })).toBe(false);
    expect(isEventPartnerRef({ mention: null })).toBe(false);
  });

  it("reads the references of a public event, and none from an older payload", async () => {
    server.use(
      http.get(`${BASE}/events/evt-1`, () =>
        HttpResponse.json({ data: { ...publicEvent, partners: [{ partnerId: "p1", mention: "Merci" }, { nope: true }] } }),
      ),
      http.get(`${BASE}/events/evt-2`, () => HttpResponse.json({ data: { ...publicEvent, id: "evt-2" } })),
    );

    expect((await getPublicEvent("evt-1"))?.partners).toEqual([{ partnerId: "p1", mention: "Merci" }]);
    expect((await getPublicEvent("evt-2"))?.partners).toEqual([]);
  });

  it("renders nothing without a visible partner", () => {
    expect(renderToStaticMarkup(<EventPartners partners={[]} variant="page" />)).toBe("");
    expect(renderToStaticMarkup(<EventPartners partners={[]} variant="discreet" />)).toBe("");
  });

  it("shows the mention in place of the description on the event page, with a sponsored link", () => {
    const html = renderToStaticMarkup(
      <EventPartners
        partners={[
          { partner: shop, mention: "Lots offerts" },
          { partner: ludo, mention: null },
        ]}
        variant="page"
      />,
    );

    expect(html).toContain("Avec le soutien de");
    expect(html).toContain("Lots offerts");
    expect(html).not.toContain("Boutique, la description.");
    expect(html).toContain("Ludothèque, la description.");
    expect(html).toContain('rel="sponsored noopener noreferrer"');
    expect(html.indexOf("Boutique")).toBeLessThan(html.indexOf("Ludothèque"));
  });

  it("thanks the partners quietly at the end of a recap", () => {
    const html = renderToStaticMarkup(<EventPartners partners={[{ partner: ludo, mention: "Prêt du matériel" }]} variant="discreet" />);

    expect(html).toContain("<aside");
    expect(html).toContain("Prêt du matériel");
    expect(html).toContain('href="https://p1.example"');
    expect(html).toContain('alt="Logo de Ludothèque"');
  });

  it("edits the draft: add (up to the ceiling, once), remove, move, mention", () => {
    const one = addEventPartner([], "p1");
    expect(one).toEqual([{ partnerId: "p1", mention: null }]);
    expect(addEventPartner(one, "p1")).toEqual(one);

    const full = Array.from({ length: MAX_EVENT_PARTNERS }, (_, i) => ({ partnerId: `p${i}`, mention: null }));
    expect(addEventPartner(full, "extra")).toHaveLength(MAX_EVENT_PARTNERS);

    const two = addEventPartner(one, "p2");
    expect(moveEventPartner(two, 1, -1).map((ref) => ref.partnerId)).toEqual(["p2", "p1"]);
    expect(moveEventPartner(two, 0, -1)).toEqual(two);
    expect(removeEventPartner(two, "p1")).toEqual([{ partnerId: "p2", mention: null }]);
    expect(withMention(two, "p2", "Lots")).toEqual([{ partnerId: "p1", mention: null }, { partnerId: "p2", mention: "Lots" }]);
    expect(withMention(two, "p2", "   ")[1].mention).toBeNull();
  });

  it("lists the chosen partners with their state and offers the others", () => {
    const admin: AdminPartner[] = [
      { ...ludo, active: true, visible: true },
      { ...shop, active: false, visible: false },
    ];
    const html = renderToStaticMarkup(
      <QueryClientProvider client={new QueryClient()}>
        <EventPartnersEditor eventId="evt-1" initial={[{ partnerId: "p2", mention: "Lots" }]} partners={admin} />
      </QueryClientProvider>,
    );

    expect(html).toContain("Choisis (1/5)");
    expect(html).toContain('value="Lots"');
    expect(html).toContain("Masqué");
    expect(html).toContain("Autres partenaires");
    expect(html).toContain("Ludothèque");
  });

  it("sends the list and surfaces the API's refusal", async () => {
    let body: unknown = null;
    server.use(
      http.put(`${BASE}/admin/events/evt-1/partners`, async ({ request }) => {
        body = await request.json();
        return HttpResponse.json({ data: null, meta: {} });
      }),
      http.put(`${BASE}/admin/events/evt-2/partners`, () =>
        HttpResponse.json({ error: { code: "validation_failed", message: "Les partenaires de l'événement sont invalides." } }, { status: 422 }),
      ),
    );

    expect(await saveEventPartners("evt-1", [{ partnerId: "p1", mention: null }])).toBeNull();
    expect(body).toEqual({ partners: [{ partnerId: "p1", mention: null }] });
    expect(await saveEventPartners("evt-2", [])).toBe("Les partenaires de l'événement sont invalides.");
  });
});
