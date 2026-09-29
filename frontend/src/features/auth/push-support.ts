/**
 * Browser push support and the state of the "Notifications sur cet appareil" card (story 40.2). Pure, so the
 * card's every case is testable without a browser.
 */

export type PushSupport = "supported" | "unsupported" | "ios-needs-install";

export type PushEnvironment = {
  hasServiceWorker: boolean;
  hasPushManager: boolean;
  hasNotification: boolean;
  isIos: boolean;
  /** Opened from the home screen (installed web app), the only place iOS allows pushes. */
  isStandalone: boolean;
};

export function detectPushSupport(env: PushEnvironment): PushSupport {
  if (env.isIos && !env.isStandalone) {
    return "ios-needs-install";
  }
  return env.hasServiceWorker && env.hasPushManager && env.hasNotification ? "supported" : "unsupported";
}

/** What the browser says about notifications for this site, as `Notification.permission` reports it. */
export type PushPermission = "default" | "granted" | "denied";

export type PushCardState = "unavailable" | "unsupported" | "ios-install" | "blocked" | "enabled" | "disabled";

export function cardState({
  publicKey,
  support,
  permission,
  subscribed,
}: {
  publicKey: string | null;
  support: PushSupport;
  permission: PushPermission;
  subscribed: boolean;
}): PushCardState {
  if (publicKey === null) return "unavailable";
  if (support === "unsupported") return "unsupported";
  if (support === "ios-needs-install") return "ios-install";
  if (permission === "denied") return "blocked";
  return permission === "granted" && subscribed ? "enabled" : "disabled";
}

/** The VAPID public key (base64url) as the bytes `pushManager.subscribe()` expects. */
export function urlBase64ToUint8Array(base64Url: string): Uint8Array<ArrayBuffer> {
  const padded = base64Url.padEnd(base64Url.length + ((4 - (base64Url.length % 4)) % 4), "=");
  const raw = atob(padded.replace(/-/g, "+").replace(/_/g, "/"));
  const bytes = new Uint8Array(new ArrayBuffer(raw.length));
  for (let i = 0; i < raw.length; i += 1) {
    bytes[i] = raw.charCodeAt(i);
  }
  return bytes;
}

/** RFC 8291's aes128gcm, or the legacy aesgcm for the few browsers that only know that one. */
export function pushContentEncoding(supported: readonly string[] | undefined): "aes128gcm" | "aesgcm" {
  if (supported === undefined || supported.includes("aes128gcm")) return "aes128gcm";
  return supported.includes("aesgcm") ? "aesgcm" : "aes128gcm";
}
