import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { fetchPushPublicKey, registerPushSubscription, removePushSubscription } from "./push-api";

const BASE = TEST_API_BASE_URL;

describe("push api (story 40.2)", () => {
  it("reads the site's public key, null when pushes are off or the API fails", async () => {
    server.use(http.get(`${BASE}/push/public-key`, () => HttpResponse.json({ data: { publicKey: "BPub" } })));
    expect(await fetchPushPublicKey()).toBe("BPub");

    server.use(http.get(`${BASE}/push/public-key`, () => HttpResponse.json({ data: { publicKey: null } })));
    expect(await fetchPushPublicKey()).toBeNull();

    server.use(http.get(`${BASE}/push/public-key`, () => new HttpResponse(null, { status: 500 })));
    expect(await fetchPushPublicKey()).toBeNull();
  });

  it("registers the browser subscription with its encoding", async () => {
    let body: unknown = null;
    server.use(
      http.post(`${BASE}/account/push-subscriptions`, async ({ request }) => {
        body = await request.json();
        return HttpResponse.json({ data: { registered: true } }, { status: 201 });
      }),
    );

    const ok = await registerPushSubscription({ endpoint: "https://push.example/1", keys: { p256dh: "p", auth: "a" } }, "aes128gcm");

    expect(ok).toBe(true);
    expect(body).toEqual({ endpoint: "https://push.example/1", keys: { p256dh: "p", auth: "a" }, contentEncoding: "aes128gcm" });
  });

  it("reports a refused registration", async () => {
    server.use(http.post(`${BASE}/account/push-subscriptions`, () => new HttpResponse(null, { status: 422 })));

    expect(await registerPushSubscription({ endpoint: "x", keys: {} }, "aes128gcm")).toBe(false);
  });

  it("removes the device by its endpoint", async () => {
    let body: unknown = null;
    server.use(
      http.delete(`${BASE}/account/push-subscriptions`, async ({ request }) => {
        body = await request.json();
        return new HttpResponse(null, { status: 204 });
      }),
    );

    expect(await removePushSubscription("https://push.example/1")).toBe(true);
    expect(body).toEqual({ endpoint: "https://push.example/1" });
  });
});
