import { http, HttpResponse } from "msw";

import { server } from "@/tests/setup";
import { TEST_API_BASE_URL } from "@/tests/constants";
import { fetchRegistrantHubAccess, hubRedirect, parseRegistrantHubAccess } from "./registrant-hub-api";

function profile(status = 200) {
  return http.get(`${TEST_API_BASE_URL}/account/profile`, () => HttpResponse.json({ data: {} }, { status }));
}

function myRegistration(body: Record<string, unknown>, status = 200) {
  return http.get(`${TEST_API_BASE_URL}/events/e1/my-registration`, () => HttpResponse.json(body, { status }));
}

/** Story 53.1: who may open the registrant hub. */
describe("registrant hub access", () => {
  it("lets a registrant in, confirmed or not", async () => {
    server.use(profile(), myRegistration({ data: { registrationId: "r1", status: "reserved", confirmed: false } }));
    await expect(fetchRegistrantHubAccess("e1")).resolves.toEqual({ kind: "registered", registrationId: "r1", confirmed: false });
  });

  it("tells a guest from a member", async () => {
    server.use(profile(401));
    await expect(fetchRegistrantHubAccess("e1")).resolves.toEqual({ kind: "guest" });
  });

  it("treats no registration and a cancelled one alike", async () => {
    server.use(profile(), myRegistration({ error: {} }, 404));
    await expect(fetchRegistrantHubAccess("e1")).resolves.toEqual({ kind: "not_registered" });
    expect(parseRegistrantHubAccess({ data: { registrationId: "r1", status: "cancelled", confirmed: true } })).toEqual({ kind: "not_registered" });
  });

  it("never bounces a registrant on a failure", async () => {
    server.use(profile(), myRegistration({ error: {} }, 500));
    await expect(fetchRegistrantHubAccess("e1")).resolves.toEqual({ kind: "error" });
    server.use(profile(502));
    await expect(fetchRegistrantHubAccess("e1")).resolves.toEqual({ kind: "error" });
    expect(parseRegistrantHubAccess({ data: {} })).toEqual({ kind: "error" });
  });

  it("sends a guest to the login and back, a non-registrant to the event", () => {
    expect(hubRedirect({ kind: "guest" }, "archilan-4")).toBe("/connexion?returnTo=%2Fevenements%2Farchilan-4%2Fmon-event");
    expect(hubRedirect({ kind: "not_registered" }, "archilan-4")).toBe("/evenements/archilan-4");
    expect(hubRedirect({ kind: "registered", registrationId: "r1", confirmed: true }, "archilan-4")).toBeNull();
    expect(hubRedirect({ kind: "error" }, "archilan-4")).toBeNull();
  });
});
