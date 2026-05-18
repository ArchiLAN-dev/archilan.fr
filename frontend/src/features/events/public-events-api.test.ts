import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { getPublicEvents, getPublicEvent } from "./public-events-api";

const BASE = TEST_API_BASE_URL;

const validEventPayload = {
  id: "evt-1",
  title: "LAN 2024",
  description: "Grande LAN",
  coverImageUrl: null,
  photoGallery: [],
  type: "lan",
  status: "published",
  startsAt: "2024-06-01T10:00:00Z",
  endsAt: "2024-06-02T18:00:00Z",
  venue: "Salle des fêtes",
  capacity: 50,
  confirmedRegistrations: 10,
  registrationOpensAt: "2024-01-01T00:00:00Z",
  registrationClosesAt: "2024-05-31T23:59:59Z",
  isPublic: true,
  hasPrivateAccessPassword: false,
  vodUrl: null,
  recapPostSlug: null,
  hasRecap: false,
  checkoutEmbedUrl: null,
  checkoutUnavailable: false,
};

describe("getPublicEvents", () => {
  it("returns parsed events on success", async () => {
    server.use(
      http.get(`${BASE}/events`, () =>
        HttpResponse.json({ data: [validEventPayload] }),
      ),
    );
    const result = await getPublicEvents();
    const allEvents = [...result.upcoming, ...result.past];
    expect(allEvents.some((e) => e.id === "evt-1")).toBe(true);
  });

  it("returns fallback events on network error", async () => {
    server.use(http.get(`${BASE}/events`, () => HttpResponse.error()));
    const result = await getPublicEvents();
    // On error, fallback is returned - shape must match
    expect(result).toHaveProperty("upcoming");
    expect(result).toHaveProperty("past");
  });

  it("returns fallback events when response fails type guard", async () => {
    server.use(http.get(`${BASE}/events`, () => HttpResponse.json({ wrong: true })));
    const result = await getPublicEvents();
    expect(result).toHaveProperty("upcoming");
    expect(result).toHaveProperty("past");
  });
});

describe("getPublicEvent", () => {
  // Use an ID that does not exist in the mock data so fallback resolves to null
  const UNKNOWN_ID = "nonexistent-event-9999";

  it("returns parsed event on success", async () => {
    server.use(
      http.get(`${BASE}/events/evt-single`, () =>
        HttpResponse.json({ data: { ...validEventPayload, id: "evt-single" } }),
      ),
    );
    const result = await getPublicEvent("evt-single");
    expect(result).not.toBeNull();
    expect(result?.id).toBe("evt-single");
  });

  it("returns null on network error for unknown ID", async () => {
    server.use(http.get(`${BASE}/events/${UNKNOWN_ID}`, () => HttpResponse.error()));
    expect(await getPublicEvent(UNKNOWN_ID)).toBeNull();
  });

  it("returns null when response fails type guard for unknown ID", async () => {
    server.use(
      http.get(`${BASE}/events/${UNKNOWN_ID}`, () => HttpResponse.json({ data: "bad" })),
    );
    expect(await getPublicEvent(UNKNOWN_ID)).toBeNull();
  });
});
