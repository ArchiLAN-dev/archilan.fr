import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { HintButton, HintConfirm } from "@/features/reachability/check-row";
import { buyPelleHint, canAfford, fetchPelleHintTerms, sessionSlotUrl, type PelleHintTerms } from "./pelle-hints";
import { pelleReasonLabel } from "./wallet-api";

const BASE = TEST_API_BASE_URL;

const terms: PelleHintTerms = { enabled: true, itemPrice: 20, locationPrice: 10, eventBalance: 5, goldBalance: 12 };

/** Story 41.3: hints bought with pelles. */
describe("pelle hints", () => {
  test("the event's pelles or gold pelles can pay", () => {
    expect(canAfford(terms, 10)).toBe(true);
    expect(canAfford(terms, 20)).toBe(false);
    expect(canAfford({ ...terms, eventBalance: 25 }, 20)).toBe(true);
    expect(canAfford({ ...terms, eventBalance: null }, 12)).toBe(true);
  });

  test("reads the terms of a slot", async () => {
    server.use(http.get(`${BASE}/sessions/s1/slots/2/pelle-hints`, () => HttpResponse.json(terms)));
    expect(await fetchPelleHintTerms(sessionSlotUrl("s1", "2"))).toEqual(terms);

    server.use(http.get(`${BASE}/sessions/s1/slots/2/pelle-hints`, () => HttpResponse.json({ enabled: "yes" })));
    expect(await fetchPelleHintTerms(sessionSlotUrl("s1", "2"))).toBeNull();
  });

  test("a purchase sends its target and request id, and throws when refused", async () => {
    let body: unknown = null;
    server.use(
      http.post(`${BASE}/sessions/s1/slots/2/pelle-hints`, async ({ request }) => {
        body = await request.json();
        return HttpResponse.json({ paidWith: "gold", price: 20, balanceAfter: 0, alreadyBought: false });
      }),
    );
    await buyPelleHint(sessionSlotUrl("s1", "2"), { kind: "item", itemName: "Grappin" }, "r1");
    expect(body).toEqual({ kind: "item", itemName: "Grappin", requestId: "r1" });

    server.use(http.post(`${BASE}/sessions/s1/slots/2/pelle-hints`, () => new HttpResponse(null, { status: 409 })));
    await expect(buyPelleHint(sessionSlotUrl("s1", "2"), { kind: "location", locationId: 7 }, "r2")).rejects.toThrow("409");
  });

  test("a weekly slot uses its own API base (story 41.8)", async () => {
    let asked = false;
    server.use(
      http.get(`${BASE}/weekly-runs/w1/entries/e1/slots/1/pelle-hints`, () => {
        asked = true;
        return HttpResponse.json({ ...terms, eventBalance: null });
      }),
    );

    expect(await fetchPelleHintTerms(`${BASE}/weekly-runs/w1/entries/e1/slots/1`)).toEqual({ ...terms, eventBalance: null });
    expect(asked).toBe(true);
  });

  test("the reasons have a label", () => {
    expect(pelleReasonLabel("hint_purchase")).toBe("Achat d'un indice");
    expect(pelleReasonLabel("hint_refund")).toBe("Remboursement d'un indice");
  });

  test("the hint button renders without the pelles option until asked", () => {
    const html = renderToStaticMarkup(
      <HintButton hintCost={10} onHint={() => Promise.resolve()} pelle={{ price: 20, affordable: true, onBuy: () => Promise.resolve() }} />,
    );

    // Idle: one button; the prices show once the player asks for a hint.
    expect(html).toContain('aria-label="Demander un indice"');
    expect(html).not.toContain("20 pelles");
  });

  test("asked, the button offers the pelles next to the points, disabled when the balance is short", () => {
    const offer = (affordable: boolean) =>
      renderToStaticMarkup(
        <HintConfirm
          free={false}
          hintCost={10}
          onCancel={() => {}}
          onConfirm={() => {}}
          onHint={() => Promise.resolve()}
          pelle={{ price: 20, affordable, onBuy: () => Promise.resolve() }}
        />,
      );

    expect(offer(true)).toContain("10 pts");
    expect(offer(true)).toContain("20 pelles");
    expect(offer(true)).not.toContain("disabled=\"\"");
    expect(offer(false)).toContain("disabled=\"\"");
    expect(offer(false)).toContain('title="Tu n&#x27;as pas assez de pelles."');
  });

  test("no pelles option for an admin's free hint", () => {
    const html = renderToStaticMarkup(
      <HintConfirm free hintCost={10} onCancel={() => {}} onConfirm={() => {}} onHint={() => Promise.resolve()} pelle={{ price: 20, affordable: true, onBuy: () => Promise.resolve() }} />,
    );

    expect(html).toContain("Gratuit (admin)");
    expect(html).not.toContain("pelles");
  });
});
