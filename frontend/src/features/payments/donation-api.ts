import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

/** Story 41.13: the HelloAsso donation form to embed, null when none is configured. */
export async function getDonationCheckoutUrl(): Promise<string | null> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/donation/checkout`, { cache: "no-store" });
    if (!response.ok) return null;
    const payload: unknown = await response.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload) || typeof payload.data !== "object" || payload.data === null) return null;
    const data = payload.data;
    if (!("checkoutEmbedUrl" in data)) return null;
    return typeof data.checkoutEmbedUrl === "string" ? data.checkoutEmbedUrl : null;
  } catch {
    return null;
  }
}
