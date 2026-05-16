import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

export async function getMembershipCheckoutUrl(): Promise<string | null> {
  try {
    const response = await apiFetch(`${env.apiBaseUrl}/membership/checkout`, {
      cache: "no-store",
    });

    if (!response.ok) {
      return null;
    }

    const payload: unknown = await response.json();

    if (!isMembershipCheckoutPayload(payload)) {
      return null;
    }

    return payload.data.checkoutEmbedUrl;
  } catch {
    return null;
  }
}

function isMembershipCheckoutPayload(
  payload: unknown,
): payload is { data: { checkoutEmbedUrl: string | null } } {
  if (typeof payload !== "object" || payload === null) return false;
  if (!("data" in payload) || typeof payload.data !== "object" || payload.data === null) return false;
  const data = payload.data;
  if (!("checkoutEmbedUrl" in data)) return false;
  const checkoutEmbedUrl = data.checkoutEmbedUrl;
  return checkoutEmbedUrl === null || typeof checkoutEmbedUrl === "string";
}
