import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { callThePasseur, dismissPasseurNotice, fetchPasseur, fetchPasseurJournal, fetchPasseurNotice, isPasseurOffer, isPasseurOpening, isPasseurState, type PasseurOffer, type PasseurOpening } from "./passeur-api";
import { PasseurJournalView } from "./passeur-journal";
import { PasseurNoticeView } from "./passeur-notice";
import { PasseurView, passeurRefreshIn } from "./passeur-panel";

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
  test("story 52.9: before the delay in BK, the chest shows but the call waits", () => {
    const html = renderToStaticMarkup(<PasseurView offer={{ ...offer, price: 60, nextDropAt: null, availableAt: "2026-11-09T14:30:00+01:00" }} onCall={neverCalled} />);

    expect(html).toContain("Le Passeur pourra ouvrir");
    expect(html).toContain("Coffre du temple");
    expect(html).toContain("disponible à 14:30");
    expect(html).toContain("disabled=\"\"");
  });

  test("an offer from an API without the delay is still read", () => {
    expect("availableAt" in offer).toBe(false);
    expect(isPasseurOffer(offer)).toBe(true);
    expect(isPasseurOffer({ ...offer, availableAt: null })).toBe(true);
    expect(isPasseurOffer({ ...offer, availableAt: 12 })).toBe(false);
  });

  test("story 52.9: the offer is read again as soon as the Passeur opens", () => {
    const opens = Date.parse("2026-11-09T14:30:00+01:00");
    const waiting = { enabled: true, offer: { ...offer, availableAt: "2026-11-09T14:30:00+01:00" } };

    expect(passeurRefreshIn(waiting, opens - 20_000)).toBe(21_000);
    expect(passeurRefreshIn(waiting, opens - 5 * 60_000)).toBe(60_000);
    expect(passeurRefreshIn(waiting, opens + 1_000)).toBe(60_000);
    expect(passeurRefreshIn({ enabled: true, offer }, opens)).toBe(60_000);
    expect(passeurRefreshIn(null, opens)).toBe(60_000);
  });

  test("the panel says whose chest, what it holds, and the price of the moment", () => {
    const html = renderToStaticMarkup(<PasseurView offer={offer} onCall={neverCalled} />);

    expect(html).toContain("Le Passeur");
    expect(html).toContain("Coffre du temple");
    expect(html).toContain("Bob");
    // Story 52.9: Bob may be there, playing something else.
    expect(html).not.toContain("absent");
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

const opening: PasseurOpening = {
  id: "o1",
  sessionId: "s1",
  status: "opened",
  buyerName: "Alice",
  absentName: "Bob",
  locationName: "Link House",
  itemName: "Claw",
  kind: "bronze",
  price: 60,
  share: 30,
  failureCode: null,
  createdAt: "2026-11-09T14:00:00+01:00",
  settledAt: "2026-11-09T14:00:05+01:00",
};

/** Story 52.5: what the Passeur opened. */
describe("ce que le Passeur a ouvert", () => {
  test("the opened world's player's notice lists the chests, for whom, and the total received", () => {
    const html = renderToStaticMarkup(
      <PasseurNoticeView notice={{ openings: [opening, { ...opening, id: "o2", itemName: "Dash", share: 20 }], total: 50, kind: "bronze" }} onDismiss={() => Promise.resolve(true)} />,
    );

    // Story 52.9: the world's player may well have been there.
    expect(html).toContain("Le Passeur a ouvert 2 emplacements de ton monde");
    expect(html).not.toContain("absence");
    expect(html).toContain("Link House");
    expect(html).toContain("Claw pour Alice");
    expect(html).toContain(">50<");
    expect(html).toContain('aria-label="Fermer"');
  });

  test("the journal shows every call, its status, and what was paid", () => {
    const html = renderToStaticMarkup(<PasseurJournalView openings={[{ ...opening, id: "o2", status: "failed", share: 0, failureCode: "not_opened" }, opening]} />);

    expect(html).toContain("Journal du Passeur");
    expect(html).toContain("1 coffre ouvert sur 2 appels");
    expect(html).toContain("Échec, remboursé");
    expect(html).toContain("Ouvert");
    expect(html).toContain('title="not_opened"');
  });

  test("API: the notice, its closing, and a journal by scope", async () => {
    let closed = false;
    server.use(
      http.get(`${BASE}/sessions/s1/passeur/notice`, () => HttpResponse.json({ data: { openings: [opening], total: 30, kind: "bronze" } })),
      http.delete(`${BASE}/sessions/s1/passeur/notice`, () => {
        closed = true;
        return new HttpResponse(null, { status: 204 });
      }),
      http.get(`${BASE}/runs/r1/passeur-openings`, () => HttpResponse.json({ data: [opening] })),
      http.get(`${BASE}/admin/events/e1/passeur-openings`, () => HttpResponse.json({ error: { code: "forbidden" } }, { status: 403 })),
    );

    expect(await fetchPasseurNotice("s1")).toEqual({ openings: [opening], total: 30, kind: "bronze" });
    expect(await dismissPasseurNotice("s1")).toBe(true);
    expect(closed).toBe(true);
    expect(await fetchPasseurJournal({ kind: "run", runId: "r1" })).toEqual([opening]);
    expect(await fetchPasseurJournal({ kind: "event", eventId: "e1" })).toBeNull();
    expect(isPasseurOpening({ ...opening, status: "lost" })).toBe(false);
  });
});
