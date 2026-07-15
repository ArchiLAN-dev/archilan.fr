import { http, HttpResponse } from "msw";

import { server } from "../../../../tests/setup";
import { TEST_API_BASE_URL } from "../../../../tests/constants";
import { generateMetadata } from "./page";

const BASE = TEST_API_BASE_URL;

// A valid PublicEventPayload whose DB id deliberately differs from the visited slug.
function eventPayloadWithId(id: string) {
  return {
    id,
    title: "LAN de printemps",
    description: "Une LAN Archipelago",
    coverImageUrl: null,
    photoGallery: [],
    type: "lan",
    status: "published",
    startsAt: "2026-06-01T10:00:00Z",
    endsAt: "2026-06-02T18:00:00Z",
    venue: "Salle des fêtes",
    capacity: 50,
    confirmedRegistrations: 10,
    registrationOpensAt: "2026-01-01T00:00:00Z",
    registrationClosesAt: "2026-05-31T23:59:59Z",
    isPublic: true,
    hasPrivateAccessPassword: false,
    vodUrl: null,
    recapPostSlug: null,
    hasRecap: false,
    checkoutEmbedUrl: null,
    checkoutUnavailable: false,
  };
}

describe("event detail generateMetadata - canonical hygiene (gap 5)", () => {
  it("canonicalises to the visited slug, not the fetched event id", async () => {
    const visitedSlug = "lan-de-printemps";
    server.use(
      http.get(`${BASE}/events/${visitedSlug}`, () =>
        HttpResponse.json({ data: eventPayloadWithId("db-id-999") }),
      ),
    );

    const meta = await generateMetadata({ params: Promise.resolve({ eventSlug: visitedSlug }) });

    expect(meta.alternates?.canonical).toBe(`/evenements/${visitedSlug}`);
    expect(meta.openGraph?.url).toBe(`/evenements/${visitedSlug}`);
    // Never the DB id.
    expect(meta.alternates?.canonical).not.toContain("db-id-999");
  });

  it("noindexes an unknown event", async () => {
    server.use(
      http.get(`${BASE}/events/inconnu`, () => new HttpResponse(null, { status: 404 })),
    );

    const meta = await generateMetadata({ params: Promise.resolve({ eventSlug: "inconnu" }) });

    expect(meta.robots).toEqual({ index: false, follow: false });
  });
});
