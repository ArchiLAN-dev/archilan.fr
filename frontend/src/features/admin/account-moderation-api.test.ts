import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { banAccount, fetchAccountActions, suspendAccount, warnAccount } from "./admin-moderation-api";

const BASE = TEST_API_BASE_URL;

describe("account moderation actions", () => {
  it("warnAccount posts the reason and returns true on 204", async () => {
    let body: unknown = null;
    server.use(
      http.post(`${BASE}/admin/community/accounts/u1/warn`, async ({ request }) => {
        body = await request.json();
        return new HttpResponse(null, { status: 204 });
      }),
    );
    expect(await warnAccount("u1", "fix it")).toBe(true);
    expect(body).toEqual({ reason: "fix it" });
  });

  it("suspendAccount posts until + reason", async () => {
    let body: unknown = null;
    server.use(
      http.post(`${BASE}/admin/community/accounts/u1/suspend`, async ({ request }) => {
        body = await request.json();
        return new HttpResponse(null, { status: 204 });
      }),
    );
    await suspendAccount("u1", "2026-07-01T00:00:00.000Z", "cooldown");
    expect(body).toEqual({ until: "2026-07-01T00:00:00.000Z", reason: "cooldown" });
  });

  it("banAccount returns false on a 422", async () => {
    server.use(http.post(`${BASE}/admin/community/accounts/u1/ban`, () => new HttpResponse(null, { status: 422 })));
    expect(await banAccount("u1", "")).toBe(false);
  });

  it("fetchAccountActions parses the audit log and drops malformed rows", async () => {
    server.use(
      http.get(`${BASE}/admin/community/accounts/u1/actions`, () =>
        HttpResponse.json({
          data: [
            { id: "a1", action: "ban", reason: "spam", createdAt: "2026-01-01T00:00:00Z", actorId: "admin", relatedReportId: null },
            { id: "bad" },
          ],
        }),
      ),
    );
    const actions = await fetchAccountActions("u1");
    expect(actions).toHaveLength(1);
    expect(actions[0].action).toBe("ban");
  });
});
