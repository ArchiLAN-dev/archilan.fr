import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

/**
 * Story 47.5 / 47.7: what a registrant needs to send their screen to the régie over the LAN media server, or why
 * they have nothing (no media server, not confirmed, broadcast refused, outside the window).
 */
export type MediaPublish =
  | { available: false; live: false; reason: string; opensAt: string | null; closesAt: string | null }
  | {
      available: true;
      /** Story 47.7: the régie agent sees the stream now (story 47.6). */
      live: boolean;
      path: string;
      key: string;
      whipUrl: string;
      srtUrl: string;
      opensAt: string;
      closesAt: string;
    };

export function mediaPublishKey(registrationId: string): readonly ["media-publish", string] {
  return ["media-publish", registrationId];
}

function isNullableString(v: unknown): v is string | null {
  return v === null || typeof v === "string";
}

export function isMediaPublish(v: unknown): v is MediaPublish {
  if (typeof v !== "object" || v === null || !("available" in v) || !("live" in v)) return false;
  if (!("opensAt" in v) || !isNullableString(v.opensAt) || !("closesAt" in v) || !isNullableString(v.closesAt)) return false;
  if (v.available === false) {
    return v.live === false && "reason" in v && typeof v.reason === "string";
  }
  return (
    v.available === true &&
    typeof v.live === "boolean" &&
    "path" in v &&
    typeof v.path === "string" &&
    "key" in v &&
    typeof v.key === "string" &&
    "whipUrl" in v &&
    typeof v.whipUrl === "string" &&
    "srtUrl" in v &&
    typeof v.srtUrl === "string" &&
    typeof v.opensAt === "string" &&
    typeof v.closesAt === "string"
  );
}

async function readMediaPublish(response: Response): Promise<MediaPublish | null> {
  if (!response.ok) return null;
  const payload: unknown = await response.json();
  if (typeof payload !== "object" || payload === null || !("data" in payload)) return null;
  return isMediaPublish(payload.data) ? payload.data : null;
}

/** Null when signed out, not the member's registration, cancelled or unreadable: the card then stays hidden. */
export async function fetchMediaPublish(registrationId: string): Promise<MediaPublish | null> {
  try {
    return await readMediaPublish(await apiFetch(`${env.apiBaseUrl}/registrations/${encodeURIComponent(registrationId)}/media-publish`));
  } catch {
    return null;
  }
}

/** A new key: the old one stops working at once. Null when it failed. */
export async function regenerateMediaKey(registrationId: string): Promise<MediaPublish | null> {
  try {
    return await readMediaPublish(
      await apiFetch(`${env.apiBaseUrl}/registrations/${encodeURIComponent(registrationId)}/media-publish/regenerate`, { method: "POST" }),
    );
  } catch {
    return null;
  }
}

