import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { adjustMemberPelles, fetchMyWallet } from "./wallet-api";

const BASE = TEST_API_BASE_URL;

const wallet = {
  gold: 120,
  events: [{ eventId: "e1", eventTitle: "ArchiLAN #3", balance: 15 }],
  history: {
    items: [
      { id: "m1", amount: 50, kind: "gold", eventId: null, eventTitle: null, reason: "admin_credit", label: "Aide", createdAt: "2026-10-03T12:00:00+00:00" },
    ],
    page: 1,
    perPage: 25,
    total: 1,
  },
};

describe("fetchMyWallet", () => {
  it("returns the wallet of the given page", async () => {
    let page: string | null = null;
    server.use(
      http.get(`${BASE}/me/wallet`, ({ request }) => {
        page = new URL(request.url).searchParams.get("page");
        return HttpResponse.json(wallet);
      }),
    );

    expect(await fetchMyWallet(2)).toEqual(wallet);
    expect(page).toBe("2");
  });

  it("returns null on an unexpected body or an error", async () => {
    server.use(http.get(`${BASE}/me/wallet`, () => HttpResponse.json({ gold: "beaucoup" })));
    expect(await fetchMyWallet()).toBeNull();

    server.use(http.get(`${BASE}/me/wallet`, () => new HttpResponse(null, { status: 401 })));
    expect(await fetchMyWallet()).toBeNull();
  });
});

describe("adjustMemberPelles", () => {
  it("posts the trimmed adjustment and returns the balances", async () => {
    let body: unknown = null;
    server.use(
      http.post(`${BASE}/admin/users/u1/pelles`, async ({ request }) => {
        body = await request.json();
        return HttpResponse.json({ movementId: "m1", balanceBefore: 10, balanceAfter: 60 }, { status: 201 });
      }),
    );

    const result = await adjustMemberPelles("u1", { direction: "credit", amount: 50, kind: "gold", eventId: null, reason: "  Aide  " });

    expect(result).toEqual({ kind: "ok", balanceBefore: 10, balanceAfter: 60 });
    expect(body).toEqual({ direction: "credit", amount: 50, kind: "gold", eventId: null, reason: "Aide" });
  });

  it("relays the server's message, such as an insufficient balance", async () => {
    server.use(
      http.post(`${BASE}/admin/users/u1/pelles`, () =>
        HttpResponse.json(
          { error: { code: "insufficient_pelles", message: "Solde insuffisant : 30 pelle(s) disponible(s).", details: { available: 30 } } },
          { status: 422 },
        ),
      ),
    );

    const result = await adjustMemberPelles("u1", { direction: "debit", amount: 31, kind: "gold", eventId: null, reason: "x" });

    expect(result).toEqual({ kind: "error", message: "Solde insuffisant : 30 pelle(s) disponible(s)." });
  });

  it("explains a refusal on one's own wallet", async () => {
    server.use(http.post(`${BASE}/admin/users/me/pelles`, () => HttpResponse.json({ error: { code: "forbidden" } }, { status: 403 })));

    const result = await adjustMemberPelles("me", { direction: "credit", amount: 5, kind: "gold", eventId: null, reason: "x" });

    expect(result).toEqual({ kind: "error", message: "Tu ne peux pas créditer ou débiter ton propre portefeuille." });
  });
});
