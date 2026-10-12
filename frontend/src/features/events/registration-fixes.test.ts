import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { fetchMyRegistrationCta, fetchRegistrationRecap } from "./events-api";
import { canContinueToRecap } from "./game-selection-gate";
import { apiFailure } from "./registration-eligibility-gate";

const BASE = TEST_API_BASE_URL;

/** Story 4.22: registration fixes before ArchiLAN 4. */
describe("registration fixes", () => {
  it("offers to sign up again once the registration is cancelled", async () => {
    server.use(
      http.get(`${BASE}/account/profile`, () => HttpResponse.json({ data: {} })),
      http.get(`${BASE}/events/evt-1/my-registration`, () =>
        HttpResponse.json({ data: { registrationId: "reg-1", status: "cancelled" } }),
      ),
    );
    expect(await fetchMyRegistrationCta("evt-1")).toEqual({ kind: "not_registered" });

    server.use(
      http.get(`${BASE}/events/evt-1/my-registration`, () =>
        HttpResponse.json({ data: { registrationId: "reg-1", status: "reserved" } }),
      ),
    );
    expect(await fetchMyRegistrationCta("evt-1")).toEqual({ kind: "registered", registrationId: "reg-1" });
  });

  it("goes on to the recap without a game when the event has no game selection", () => {
    const base = { registrationOpen: true, selectedCount: 0, saved: false };
    expect(canContinueToRecap({ ...base, gameSelectionEnabled: false })).toBe(true);
    expect(canContinueToRecap({ ...base, gameSelectionEnabled: true })).toBe(false);
    expect(canContinueToRecap({ ...base, gameSelectionEnabled: true, selectedCount: 2, saved: true })).toBe(true);
    expect(canContinueToRecap({ ...base, gameSelectionEnabled: false, registrationOpen: false })).toBe(false);
  });

  it("reads why the API refused a reservation", () => {
    expect(apiFailure({ error: { code: "email_not_verified", message: "Confirme ton email." } })).toEqual({
      code: "email_not_verified",
      message: "Confirme ton email.",
    });
    expect(apiFailure({ error: "nope" })).toEqual({ code: null, message: null });
    expect(apiFailure(null)).toEqual({ code: null, message: null });
  });

  it("tells a confirmed registration from a reserved one on the recap", async () => {
    const payload = (submittedAt: string | null) => ({
      data: {
        registrationId: "reg-1",
        eventTitle: "ArchiLAN 4",
        gameSelectionEnabled: false,
        registrationOpen: true,
        submittedAt,
        slots: [],
        availableGames: [],
      },
    });

    server.use(http.get(`${BASE}/registrations/reg-1/game-selection`, () => HttpResponse.json(payload(null))));
    const reserved = await fetchRegistrationRecap("reg-1");
    expect(reserved.kind === "success" ? reserved.data.submittedAt : "x").toBeNull();

    server.use(
      http.get(`${BASE}/registrations/reg-1/game-selection`, () => HttpResponse.json(payload("2026-10-20T08:00:00+00:00"))),
    );
    const confirmed = await fetchRegistrationRecap("reg-1");
    expect(confirmed.kind === "success" ? confirmed.data.submittedAt : null).toBe("2026-10-20T08:00:00+00:00");
  });
});
