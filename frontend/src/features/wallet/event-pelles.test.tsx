import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { messageFor } from "@/features/community/notification-center";
import { EventPellesView, distributionConfirmation, distributionSummary } from "./admin-event-pelles-page";
import { distributeEventPelles, fetchEventPelles, type EventPelles } from "./event-pelles-api";
import { pelleReasonLabel } from "./wallet-api";

const BASE = TEST_API_BASE_URL;

const running: EventPelles = {
  eventId: "e1",
  eventTitle: "ArchiLAN #3",
  endsAt: "2030-11-02T18:00:00+00:00",
  ended: false,
  distributed: 40,
  inCirculation: 25,
  converted: 0,
  destroyed: 0,
  participants: [
    { userId: "u1", displayName: "Alice", balance: 25, banned: false },
    { userId: "u2", displayName: "Dan", balance: 0, banned: true },
  ],
};

function render(data: EventPelles): string {
  return renderToStaticMarkup(
    <QueryClientProvider client={new QueryClient()}>
      <EventPellesView data={data} />
    </QueryClientProvider>,
  );
}

/** Story 41.2: event pelles, admin side. */
describe("event pelles page", () => {
  test("a running event shows its registrants, their balance and the distribution form", () => {
    const html = render(running);

    expect(html).toContain("Pelles · ArchiLAN #3");
    expect(html).toContain("Alice");
    expect(html).toContain("banni");
    expect(html).toContain("Tous les inscrits (1)");
    expect(html).toContain("Distribuer à 1 membre");
    expect(html).not.toContain("Converties en or");
  });

  test("an ended event shows what was converted and destroyed, and no form", () => {
    const html = render({ ...running, ended: true, inCirculation: 0, converted: 2, destroyed: 25 });

    expect(html).toContain("Converties en or");
    expect(html).toContain("Détruites");
    expect(html).not.toContain("Distribuer à");
  });

  test("the confirmation and the outcome in words", () => {
    expect(distributionConfirmation(15, 3, "Happening du samedi")).toEqual({
      title: "Distribuer des pelles ?",
      description: "15 pelles à 3 membres, soit 45 pelles au total. Libellé : « Happening du samedi ».",
    });
    expect(distributionConfirmation(1, 1, "Quiz").description).toBe("1 pelle à 1 membre, soit 1 pelle au total. Libellé : « Quiz ».");
    expect(distributionSummary({ credited: 2, skipped: 1, alreadyCredited: 0 })).toBe("2 membres crédités, 1 sauté (compte banni ou supprimé).");
  });

  test("the new reasons have a label, and the notification names the event", () => {
    expect(pelleReasonLabel("event_conversion")).toBe("Conversion en or (fin d'événement)");
    expect(
      messageFor({ id: "n", type: "pelles_adjusted", createdAt: "2026-10-03T10:00:00Z", read: false, actor: null, data: { amount: 15, kind: "event", reason: "Happening", eventTitle: "ArchiLAN #3" } }),
    ).toBe("Tu as reçu 15 pelles pour « ArchiLAN #3 » : Happening");
  });
});

describe("event pelles API", () => {
  test("reads the page", async () => {
    server.use(http.get(`${BASE}/admin/events/e1/pelles`, () => HttpResponse.json(running)));
    expect(await fetchEventPelles("e1")).toEqual(running);
  });

  test("sends a selection, or nothing for every registrant", async () => {
    const bodies: unknown[] = [];
    server.use(
      http.post(`${BASE}/admin/events/e1/pelles`, async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ credited: 1, skipped: 0, alreadyCredited: 0 });
      }),
    );

    await distributeEventPelles("e1", { amount: 5, label: " Défi ", requestId: "r1", userIds: ["u1"] });
    const result = await distributeEventPelles("e1", { amount: 5, label: "Défi", requestId: "r2", userIds: null });

    expect(bodies).toEqual([
      { amount: 5, label: "Défi", requestId: "r1", userIds: ["u1"] },
      { amount: 5, label: "Défi", requestId: "r2" },
    ]);
    expect(result).toEqual({ kind: "ok", credited: 1, skipped: 0, alreadyCredited: 0 });
  });

  test("relays the server's refusal", async () => {
    server.use(
      http.post(`${BASE}/admin/events/e1/pelles`, () =>
        HttpResponse.json({ error: { code: "event_ended", message: "Cet événement est terminé : ses pelles ont expiré.", details: [] } }, { status: 422 }),
      ),
    );

    expect(await distributeEventPelles("e1", { amount: 5, label: "x", requestId: "r", userIds: null })).toEqual({
      kind: "error",
      message: "Cet événement est terminé : ses pelles ont expiré.",
    });
  });
});
