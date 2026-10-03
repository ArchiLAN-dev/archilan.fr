import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { ItemBountiesView, fetchItemBounties, postItemBounty, withdrawItemBounty, type ItemBounties } from "./item-bounties";
import { pelleReasonLabel } from "./wallet-api";

const BASE = TEST_API_BASE_URL;

const open: ItemBounties = {
  enabled: true,
  bounties: [
    { id: "b1", slotName: "Alice", itemName: "Grappin", amount: 50, reward: 45, mine: true },
    { id: "b2", slotName: "Bob", itemName: "Bombe", amount: 20, reward: 18, mine: false },
  ],
};

const noop = () => Promise.resolve(null);

/** Story 41.4: bounties on items. */
describe("item bounties", () => {
  test("the panel lists what to send, to whom, for how much; only one's own bounty can be withdrawn", () => {
    const html = renderToStaticMarkup(<ItemBountiesView bounties={open.bounties} missingItems={["Grappin", "Clé"]} onPost={noop} onWithdraw={noop} />);

    expect(html).toContain("Primes de la partie");
    expect(html).toContain("Grappin");
    expect(html).toContain(" pour Bob");
    expect(html).toContain("45");
    expect(html).toContain('aria-label="Retirer la prime sur Grappin"');
    expect(html).not.toContain('aria-label="Retirer la prime sur Bombe"');
    expect(html).toContain("Poser une prime");
    expect(html).toContain("<option value=\"Clé\">Clé</option>");
  });

  test("no form when nothing is missing, and a word when no bounty is open", () => {
    const html = renderToStaticMarkup(<ItemBountiesView bounties={[]} missingItems={[]} onPost={noop} onWithdraw={noop} />);

    expect(html).toContain("Aucune prime en cours.");
    expect(html).not.toContain("Poser une prime");
  });

  test("API: reads, posts with a request id, withdraws, and relays refusals", async () => {
    let body: unknown = null;
    server.use(
      http.get(`${BASE}/sessions/s1/slots/1/bounties`, () => HttpResponse.json(open)),
      http.post(`${BASE}/sessions/s1/slots/1/bounties`, async ({ request }) => {
        body = await request.json();
        return HttpResponse.json({ id: "b3" }, { status: 201 });
      }),
      http.delete(`${BASE}/sessions/s1/bounties/b1`, () => new HttpResponse(null, { status: 204 })),
    );

    expect(await fetchItemBounties("s1", "1")).toEqual(open);
    expect(await postItemBounty("s1", "1", "Clé", 30, "r1")).toBeNull();
    expect(body).toEqual({ itemName: "Clé", amount: 30, requestId: "r1" });
    expect(await withdrawItemBounty("s1", "b1")).toBeNull();

    server.use(
      http.post(`${BASE}/sessions/s1/slots/1/bounties`, () =>
        HttpResponse.json({ error: { code: "bounty_exists", message: "Une prime est déjà posée sur cet objet.", details: [] } }, { status: 409 }),
      ),
    );
    expect(await postItemBounty("s1", "1", "Grappin", 30, "r2")).toBe("Une prime est déjà posée sur cet objet.");
  });

  test("the reasons have a label", () => {
    expect(pelleReasonLabel("bounty_escrow")).toBe("Prime posée");
    expect(pelleReasonLabel("bounty_reward")).toBe("Prime gagnée");
    expect(pelleReasonLabel("bounty_refund")).toBe("Prime rendue");
  });
});
