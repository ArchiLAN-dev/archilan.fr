import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import {
  fetchAccountModerationContact,
  fetchBlockedModerationContact,
  sendAccountModerationMessage,
  sendBlockedModerationMessage,
} from "./moderation-contact-api";

const BASE = TEST_API_BASE_URL;
const MESSAGE = { id: "m1", author: "member", source: "discord_dm", body: "J'aimerais être remboursé", createdAt: "2026-09-27T10:00:00+00:00" };
const REPLY = { id: "m2", author: "staff", source: "site", body: "C'est en cours", createdAt: "2026-09-27T11:00:00+00:00" };

/**
 * Story 39.2 : le membre sanctionné écrit à la modération, depuis son compte ou avec le laissez-passer
 * d'une connexion refusée.
 */
describe("moderation-contact-api", () => {
  it("lit la sanction et les messages d'un membre bloqué", async () => {
    server.use(
      http.get(`${BASE}/moderation-contact`, () =>
        HttpResponse.json({
          data: { status: "suspended", reason: "Triche", suspendedUntil: "2026-10-10T00:00:00+00:00", messages: [MESSAGE] },
        }),
      ),
    );

    await expect(fetchBlockedModerationContact()).resolves.toEqual({
      status: "suspended",
      reason: "Triche",
      suspendedUntil: "2026-10-10T00:00:00+00:00",
      messages: [MESSAGE],
    });
  });

  it("sans laissez-passer valide, rien à montrer", async () => {
    server.use(
      http.get(`${BASE}/moderation-contact`, () =>
        HttpResponse.json({ error: { code: "moderation_contact_expired", message: "x", details: {} } }, { status: 401 }),
      ),
    );

    await expect(fetchBlockedModerationContact()).resolves.toBeNull();
  });

  it("lit le fil du membre connecté", async () => {
    server.use(
      http.get(`${BASE}/account/moderation-contact`, () =>
        HttpResponse.json({ data: { available: true, messages: [MESSAGE, REPLY] } }),
      ),
    );

    await expect(fetchAccountModerationContact()).resolves.toEqual({ available: true, messages: [MESSAGE, REPLY] });
  });

  it("envoie un message et remonte le refus de l'API", async () => {
    let received: unknown = null;
    server.use(
      http.post(`${BASE}/moderation-contact`, async ({ request }) => {
        received = await request.json();
        return HttpResponse.json({ data: { sent: true } }, { status: 201 });
      }),
      http.post(`${BASE}/account/moderation-contact`, () =>
        HttpResponse.json(
          { error: { code: "too_many_messages", message: "Trop de messages en une heure, réessaie plus tard.", details: {} } },
          { status: 429 },
        ),
      ),
    );

    await expect(sendBlockedModerationMessage("Bonjour")).resolves.toEqual({ ok: true });
    expect(received).toEqual({ body: "Bonjour" });
    await expect(sendAccountModerationMessage("Encore")).resolves.toEqual({
      ok: false,
      message: "Trop de messages en une heure, réessaie plus tard.",
    });
  });
});
