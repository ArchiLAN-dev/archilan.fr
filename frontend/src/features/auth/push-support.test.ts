import { cardState, detectPushSupport, pushContentEncoding, urlBase64ToUint8Array } from "./push-support";

const desktop = { hasServiceWorker: true, hasPushManager: true, hasNotification: true, isIos: false, isStandalone: false };

/**
 * Story 40.2. What the "Notifications sur cet appareil" card can say, from what the browser offers and
 * what the member already chose.
 */
describe("push support", () => {
  test("a desktop browser with service workers, push and notifications is supported", () => {
    expect(detectPushSupport(desktop)).toBe("supported");
  });

  test("an iPhone outside the home screen cannot receive pushes and is told how", () => {
    expect(detectPushSupport({ ...desktop, hasPushManager: false, isIos: true })).toBe("ios-needs-install");
    expect(detectPushSupport({ ...desktop, isIos: true, isStandalone: true })).toBe("supported");
  });

  test("a browser without the APIs is unsupported", () => {
    expect(detectPushSupport({ ...desktop, hasServiceWorker: false })).toBe("unsupported");
    expect(detectPushSupport({ ...desktop, hasNotification: false })).toBe("unsupported");
  });
});

describe("card state", () => {
  const base = { publicKey: "key", support: "supported" as const, permission: "default" as const, subscribed: false };

  test("no site key means pushes are off, whatever the browser", () => {
    expect(cardState({ ...base, publicKey: null })).toBe("unavailable");
  });

  test("the browser's limits come next", () => {
    expect(cardState({ ...base, support: "unsupported" })).toBe("unsupported");
    expect(cardState({ ...base, support: "ios-needs-install" })).toBe("ios-install");
  });

  test("a refused permission is blocked until the browser settings change", () => {
    expect(cardState({ ...base, permission: "denied" })).toBe("blocked");
  });

  test("granted and subscribed is on, anything else is off", () => {
    expect(cardState({ ...base, permission: "granted", subscribed: true })).toBe("enabled");
    expect(cardState({ ...base, permission: "granted", subscribed: false })).toBe("disabled");
    expect(cardState(base)).toBe("disabled");
  });
});

describe("push helpers", () => {
  test("the VAPID key is decoded from base64url to the bytes the browser expects", () => {
    expect(Array.from(urlBase64ToUint8Array("AQID_-8"))).toEqual([1, 2, 3, 255, 239]);
  });

  test("aes128gcm is preferred, aesgcm only for the old browsers that lack it", () => {
    expect(pushContentEncoding(["aes128gcm", "aesgcm"])).toBe("aes128gcm");
    expect(pushContentEncoding(["aesgcm"])).toBe("aesgcm");
    expect(pushContentEncoding(undefined)).toBe("aes128gcm");
  });
});
