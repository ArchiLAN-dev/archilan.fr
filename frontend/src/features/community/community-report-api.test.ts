import { http, HttpResponse } from "msw";
import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { reportProfile } from "./community-report-api";

const BASE = TEST_API_BASE_URL;

describe("reportProfile", () => {
  it("posts the structured payload and maps 204 to ok", async () => {
    let body: unknown = null;
    server.use(
      http.post(`${BASE}/community/profiles/bob/report`, async ({ request }) => {
        body = await request.json();
        return new HttpResponse(null, { status: 204 });
      }),
    );

    const result = await reportProfile("bob", { category: "avatar", problem: "nudity", comment: "  vu  " });
    expect(result).toBe("ok");
    expect(body).toEqual({ category: "avatar", problem: "nudity", comment: "vu" });
  });

  it("sends a null comment when blank", async () => {
    let body: unknown = null;
    server.use(
      http.post(`${BASE}/community/profiles/bob/report`, async ({ request }) => {
        body = await request.json();
        return new HttpResponse(null, { status: 204 });
      }),
    );

    await reportProfile("bob", { category: "bio", problem: "spam", comment: "   " });
    expect(body).toEqual({ category: "bio", problem: "spam", comment: null });
  });

  it("maps status codes to coarse results", async () => {
    server.use(http.post(`${BASE}/community/profiles/me/report`, () => new HttpResponse(null, { status: 403 })));
    expect(await reportProfile("me", { category: "bio", problem: "spam", comment: "" })).toBe("forbidden");

    server.use(http.post(`${BASE}/community/profiles/x/report`, () => new HttpResponse(null, { status: 422 })));
    expect(await reportProfile("x", { category: "nope", problem: "spam", comment: "" })).toBe("invalid");
  });

  it("returns error on network failure", async () => {
    server.use(http.post(`${BASE}/community/profiles/bob/report`, () => HttpResponse.error()));
    expect(await reportProfile("bob", { category: "bio", problem: "spam", comment: "" })).toBe("error");
  });
});
