import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { RegistrationList } from "@/features/admin/admin-registration-dashboard";
import type { AdminRegistration } from "@/features/admin/admin-registration-api";
import { fetchBroadcastConsent, isBroadcastConsent, saveBroadcastConsent } from "./broadcast-consent-api";
import { BroadcastConsentForm } from "./broadcast-consent-card";

const BASE = TEST_API_BASE_URL;

function registration(id: string, displayName: string, broadcastConsent: boolean): AdminRegistration {
  return {
    registrationId: id,
    status: "reserved",
    usedPrivateAccess: false,
    broadcastConsent,
    createdAt: "2026-10-01T10:00:00+02:00",
    submittedAt: null,
    participant: { userId: `u-${id}`, displayName, email: `${id}@example.org` },
    selectedGames: [],
    gameSelectionComplete: false,
    payment: null,
  };
}

/** Story 47.1: the member lets the ArchiLAN broadcast show their stream. */
describe("broadcast consent", () => {
  it("reads the member's choice and folds a refusal to null", async () => {
    expect(isBroadcastConsent({ consent: true, editable: false })).toBe(true);
    expect(isBroadcastConsent({ consent: "yes", editable: true })).toBe(false);

    server.use(http.get(`${BASE}/registrations/r1/broadcast-consent`, () => HttpResponse.json({ data: { consent: true, editable: true }, meta: [] })));
    expect(await fetchBroadcastConsent("r1")).toEqual({ consent: true, editable: true });

    server.use(http.get(`${BASE}/registrations/r2/broadcast-consent`, () => new HttpResponse(null, { status: 404 })));
    expect(await fetchBroadcastConsent("r2")).toBeNull();
  });

  it("sends the choice, and explains a closed one", async () => {
    let sent: unknown = null;
    server.use(
      http.put(`${BASE}/registrations/r1/broadcast-consent`, async ({ request }) => {
        sent = await request.json();
        return HttpResponse.json({ data: { consent: true }, meta: [] });
      }),
    );
    expect(await saveBroadcastConsent("r1", true)).toBeNull();
    expect(sent).toEqual({ consent: true });

    server.use(http.put(`${BASE}/registrations/r2/broadcast-consent`, () => new HttpResponse(null, { status: 422 })));
    expect(await saveBroadcastConsent("r2", true)).toContain("plus modifiable");
  });

  it("shows the box as saved", () => {
    const html = renderToStaticMarkup(
      <QueryClientProvider client={new QueryClient()}>
        <BroadcastConsentForm consent registrationId="r1" />
      </QueryClientProvider>,
    );
    expect(html).toContain("La régie ArchiLAN peut diffuser mon stream Twitch pendant l&#x27;événement");
    expect(html).toContain('checked=""');
  });

  it("marks the members who opted out on the admin registrations list", () => {
    const html = renderToStaticMarkup(
      <RegistrationList
        eventId="e1"
        highlightedRegistrationIds={new Set()}
        openNotes={{}}
        state={{ kind: "ready", registrations: [registration("r1", "Alice", true), registration("r2", "Bob", false)], total: 2 }}
      />,
    );
    expect(html.match(/Refuse d&#x27;être diffusé par la régie/g)).toHaveLength(2);
  });
});
