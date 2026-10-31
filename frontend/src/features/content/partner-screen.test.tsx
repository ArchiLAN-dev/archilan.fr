import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { PartnerScreenLinks } from "@/features/admin/partner-screen-links";
import {
  parsePartnerScreenParams,
  PartnerOverlay,
  partnerScreenUrl,
  PartnerScreenWall,
  rotationIndex,
} from "./partner-screen";
import { fetchPartnerScreen } from "./partner-screen-api";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import type { Partner } from "./partners-api";

const BASE = TEST_API_BASE_URL;

const ludo: Partner = { id: "p1", name: "Ludothèque", description: "Des jeux à prêter.", url: "https://p1.example", logoUrl: "http://minio.test/p1.png" };
const shop: Partner = { id: "p2", name: "Boutique", description: "", url: "https://p2.example", logoUrl: "http://minio.test/p2.png" };

const event = {
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
  partners: [{ partnerId: "p2", mention: "Lots offerts" }],
};

/** Story 45.4: the partner screen, for OBS and the LAN. */
describe("partner screen", () => {
  it("reads its parameters with its own defaults", () => {
    const overlay = parsePartnerScreenParams(new URLSearchParams(""));
    expect(overlay.mode).toBe("overlay");
    expect(overlay.eventId).toBeNull();
    expect(overlay.overlay.pos).toBe("bottom-right");
    expect(overlay.seconds).toBe(8);

    const screen = parsePartnerScreenParams(new URLSearchParams("mode=ecran&event=evt-1&pos=top-left&duration=20"));
    expect(screen.mode).toBe("ecran");
    expect(screen.eventId).toBe("evt-1");
    expect(screen.overlay.pos).toBe("top-left");
    expect(screen.seconds).toBe(20);
    expect(parsePartnerScreenParams(new URLSearchParams("mode=ecran")).seconds).toBe(12);

    expect(parsePartnerScreenParams(new URLSearchParams("event=../admin")).eventId).toBeNull();
  });

  it("builds the links to paste", () => {
    expect(partnerScreenUrl("https://archilan.fr", { eventId: null, mode: "overlay" })).toBe("https://archilan.fr/o/partenaires");
    expect(partnerScreenUrl("https://archilan.fr", { eventId: "evt-1", mode: "ecran" })).toBe("https://archilan.fr/o/partenaires?event=evt-1&mode=ecran");
  });

  it("rotates through the partners and loops", () => {
    expect([0, 1, 2, 3].map((tick) => rotationIndex(tick, 3))).toEqual([0, 1, 2, 0]);
    expect(rotationIndex(5, 0)).toBe(0);
  });

  it("shows every visible partner without an event, and only the event's with one", async () => {
    server.use(
      http.get(`${BASE}/partners`, () => HttpResponse.json({ data: [ludo, shop] })),
      http.get(`${BASE}/events/evt-1`, () => HttpResponse.json({ data: event })),
    );

    expect(await fetchPartnerScreen(null)).toEqual([
      { partner: ludo, mention: null },
      { partner: shop, mention: null },
    ]);
    expect(await fetchPartnerScreen("evt-1")).toEqual([{ partner: shop, mention: "Lots offerts" }]);
  });

  it("keeps its last list when a read fails or the event is gone", async () => {
    server.use(
      http.get(`${BASE}/partners`, () => HttpResponse.error()),
      http.get(`${BASE}/events/gone`, () => HttpResponse.json({ error: { code: "not_found" } }, { status: 404 })),
    );
    expect(await fetchPartnerScreen(null)).toBeNull();

    server.use(http.get(`${BASE}/partners`, () => HttpResponse.json({ data: [ludo] })));
    expect(await fetchPartnerScreen("gone")).toBeNull();
  });

  it("puts nothing on air without a partner, and one partner with its mention otherwise", () => {
    const params = parsePartnerScreenParams(new URLSearchParams("")).overlay;
    expect(renderToStaticMarkup(<PartnerOverlay current={null} params={params} />)).toBe("");

    const html = renderToStaticMarkup(<PartnerOverlay current={{ partner: shop, mention: "Lots offerts" }} params={params} />);
    expect(html).toContain("Boutique");
    expect(html).toContain("Lots offerts");
    expect(html).toContain('alt="Logo de Boutique"');
    expect(html).toContain("items-end justify-end");
  });

  it("thanks the partners on the LAN screen, with the description when there is no mention", () => {
    const html = renderToStaticMarkup(<PartnerScreenWall current={{ partner: ludo, mention: null }} ids={["p1", "p2"]} />);
    expect(html).toContain("Merci à nos partenaires");
    expect(html).toContain("Des jeux à prêter.");
    expect(html).toContain('aria-current="true"');

    const empty = renderToStaticMarkup(<PartnerScreenWall current={null} ids={[]} />);
    expect(empty).toContain("Merci à nos partenaires");
    expect(empty).not.toContain("<ol");
  });

  it("gives the admin both links, scoped to the event when there is one", () => {
    const html = renderToStaticMarkup(<PartnerScreenLinks eventId="evt-1" />);
    expect(html).toContain("/o/partenaires?event=evt-1</code>");
    expect(html).toContain("/o/partenaires?event=evt-1&amp;mode=ecran</code>");
    expect(html).toContain("<iframe");

    expect(renderToStaticMarkup(<PartnerScreenLinks eventId={null} />)).toContain("/o/partenaires</code>");
  });
});
