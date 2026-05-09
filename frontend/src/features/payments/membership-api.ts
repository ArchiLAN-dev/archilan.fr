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
  const data =
    payload && typeof payload === "object" && "data" in payload
      ? (payload as { data: unknown }).data
      : null;

  if (!data || typeof data !== "object" || !("checkoutEmbedUrl" in data)) {
    return false;
  }

  const checkoutEmbedUrl = (data as { checkoutEmbedUrl: unknown }).checkoutEmbedUrl;

  return checkoutEmbedUrl === null || typeof checkoutEmbedUrl === "string";
}
