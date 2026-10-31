import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { EventList } from "./admin-event-dashboard";
import { featureAdminEventOnHome, type AdminEvent } from "./admin-events-api";

const BASE = TEST_API_BASE_URL;

function adminEvent(id: string, status: AdminEvent["status"], featuredOnHome = false): AdminEvent {
  return {
    id,
    title: `ArchiLAN #${id}`,
    description: "",
    coverImageUrl: null,
    photoGallery: [],
    status,
    startsAt: "2026-11-13T17:00:00+00:00",
    endsAt: "2026-11-15T17:00:00+00:00",
    venue: "Clermont-Ferrand",
    capacity: 60,
    confirmedRegistrations: 0,
    isAtCapacity: false,
    registrationOpensAt: "2026-10-01T00:00:00+00:00",
    registrationClosesAt: "2026-11-10T00:00:00+00:00",
    isPublic: true,
    visibility: "public",
    hasPrivateAccessPassword: false,
    gameSelectionEnabled: false,
    vodUrl: null,
    recapPostSlug: null,
    hasRecap: false,
    featuredOnHome,
    createdAt: "2026-09-01T00:00:00+00:00",
    updatedAt: "2026-09-01T00:00:00+00:00",
  };
}

/** Story 3.15: the admin picks the event featured on the home page from the events list. */
describe("event featured on the home page, admin side", () => {
  it("marks the featured event and offers the choice on public events only", () => {
    const noop = () => {};
    const html = renderToStaticMarkup(
      <EventList
        onConfigurePrivateAccess={noop}
        onConfigureRecap={noop}
        onFeature={noop}
        onTransition={noop}
        state={{ kind: "ready", events: [adminEvent("1", "published", true), adminEvent("2", "completed"), adminEvent("3", "draft")] }}
      />,
    );
    // The table and the mobile cards both render each event.
    expect(html.match(/>Accueil</g)).toHaveLength(2);
    expect(html.match(/Retirer de l&#x27;accueil/g)).toHaveLength(2);
    expect(html.match(/Mettre en avant/g)).toHaveLength(2);
  });

  it("sends the choice, and reads the API's message on a refusal", async () => {
    let sent: unknown = null;
    server.use(
      http.put(`${BASE}/admin/events/e1/featured`, async ({ request }) => {
        sent = await request.json();
        return HttpResponse.json({ data: null, meta: {} });
      }),
    );
    expect(await featureAdminEventOnHome("e1", true)).toBeNull();
    expect(sent).toEqual({ featured: true });

    server.use(
      http.put(`${BASE}/admin/events/e2/featured`, () =>
        HttpResponse.json({ error: { code: "validation_failed", message: "Cet événement ne peut pas être mis en avant.", details: { featured: ["Publiez l'événement avant de le mettre en avant."] } } }, { status: 422 }),
      ),
    );
    expect(await featureAdminEventOnHome("e2", true)).toBe("Publiez l'événement avant de le mettre en avant.");
  });
});
