import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { callThePasseur, fetchPasseur, isPasseurState, type PasseurOffer } from "./passeur-api";
import { PasseurView } from "./passeur-panel";

const BASE = TEST_API_BASE_URL;

const offer: PasseurOffer = {
  findingSlot: 2,
  absentPlayerName: "Bob",
  locationId: 1234,
  locationName: "Coffre du temple",
  itemName: "Grappin",
  price: 54,
  basePrice: 60,
  kind: "event",
  balance: 100,
  blockedSince: "2026-11-09T14:00:00+01:00",
  nextDropAt: "2026-11-09T14:30:00+01:00",
};

const neverCalled = () => Promise.reject(new Error("not called"));

/** Story 52.4: the Passeur on a slot page. */
describe("le Passeur", () => {
  test("the panel says whose chest, what it holds, and the price of the moment", () => {
    const html = renderToStaticMarkup(<PasseurView offer={offer} onCall={neverCalled} />);

    expect(html).toContain("Le Passeur");
    expect(html).toContain("Coffre du temple");
    expect(html).toContain("Bob");
    expect(html).toContain("Grappin");
    expect(html).toContain("(au lieu de 60)");
    expect(html).toContain("Prochaine baisse à 14:30");
    expect(html).toContain("Faire appel au Passeur");
    expect(html).not.toContain("disabled=\"\"");
  });

  test("the call is disabled when the balance is short", () => {
    const html = renderToStaticMarkup(<PasseurView offer={{ ...offer, kind: "bronze", balance: 10, price: 60, nextDropAt: null }} onCall={neverCalled} />);

    expect(html).toContain("disabled=\"\"");
    expect(html).toContain("Il te manque des pelles de bronze.");
    expect(html).not.toContain("au lieu de");
    expect(html).not.toContain("Prochaine baisse");
  });

  test("API: reads the offer, rejects a malformed one", async () => {
    server.use(http.get(`${BASE}/sessions/s1/slots/1/passeur`, () => HttpResponse.json({ data: { enabled: true, offer } })));
    expect(await fetchPasseur("s1", "1")).toEqual({ enabled: true, offer });

    expect(isPasseurState({ enabled: true, offer: null })).toBe(true);
    expect(isPasseurState({ enabled: true, offer: { ...offer, kind: "gold" } })).toBe(false);

    server.use(http.get(`${BASE}/sessions/s1/slots/1/passeur`, () => HttpResponse.json({ error: { code: "forbidden" } }, { status: 403 })));
    expect(await fetchPasseur("s1", "1")).toBeNull();
  });

  test("API: calls with the shown price and a request id, and relays a refusal with the new price", async () => {
    let body: unknown = null;
    server.use(
      http.post(`${BASE}/sessions/s1/slots/1/passeur`, async ({ request }) => {
        body = await request.json();
        return HttpResponse.json({ data: { kind: "event", price: 54, share: 27, itemName: "Grappin", absentPlayerName: "Bob", replayed: false } });
      }),
    );
    expect(await callThePasseur("s1", "1", offer, "req-1")).toEqual({ kind: "ok", itemName: "Grappin", absentPlayerName: "Bob", price: 54 });
    expect(body).toEqual({ findingSlot: 2, locationId: 1234, price: 54, requestId: "req-1" });

    server.use(
      http.post(`${BASE}/sessions/s1/slots/1/passeur`, () =>
        HttpResponse.json({ error: { code: "passeur_price_changed", message: "Le prix a changé.", details: { price: 48 } } }, { status: 409 }),
      ),
    );
    expect(await callThePasseur("s1", "1", offer, "req-2")).toEqual({ kind: "error", code: "passeur_price_changed", message: "Le prix a changé.", price: 48 });
  });
});
