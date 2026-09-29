import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

/** Browser pushes (story 40.2): the site's VAPID public key, null while pushes are off. */
export async function fetchPushPublicKey(): Promise<string | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/push/public-key`);
    if (!res.ok) return null;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json)) return null;
    const data: unknown = json.data;
    if (typeof data !== "object" || data === null || !("publicKey" in data)) return null;
    return typeof data.publicKey === "string" && data.publicKey !== "" ? data.publicKey : null;
  } catch {
    return null;
  }
}

/** `PushSubscription.toJSON()`: the push service endpoint and the keys to encrypt for. */
export type BrowserPushSubscription = { endpoint?: string; keys?: Record<string, string> };

export async function registerPushSubscription(
  subscription: BrowserPushSubscription,
  contentEncoding: "aes128gcm" | "aesgcm",
): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/account/push-subscriptions`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ endpoint: subscription.endpoint, keys: subscription.keys, contentEncoding }),
    });
    return res.ok;
  } catch {
    return false;
  }
}

export async function removePushSubscription(endpoint: string): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/account/push-subscriptions`, {
      method: "DELETE",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ endpoint }),
    });
    return res.ok;
  } catch {
    return false;
  }
}
