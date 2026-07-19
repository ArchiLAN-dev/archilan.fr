import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasStringProp } from "@/lib/type-guards";
import type { SlotEntry } from "@/features/reachability/types";

/**
 * Lists a session's player slots (index + display name) for the admin slot switcher, excluding the
 * injected TextOnly "Bridge" observer, sorted by slot index. Authenticated (admin) via the session
 * `/players` endpoint, which proxies the bridge state. Returns `null` on any error (e.g. the session
 * is not running yet, so no bridge state) - the switcher then renders nothing. Never throws.
 */
export async function fetchSessionSlots(sessionId: string): Promise<SlotEntry[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/sessions/${encodeURIComponent(sessionId)}/players`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null) return null;
    const data: unknown = "data" in payload ? payload.data : null;
    if (typeof data !== "object" || data === null || !("slots" in data)) return null;
    const slots: unknown = data.slots;
    if (typeof slots !== "object" || slots === null) return null;
    return Object.entries(slots)
      .flatMap(([index, value]: [string, unknown]): SlotEntry[] => {
        if (typeof value !== "object" || value === null || !hasStringProp(value, "slot_name")) return [];
        if (value.slot_name === "Bridge") return [];
        return [{ index, name: value.slot_name }];
      })
      .sort((a, b) => Number(a.index) - Number(b.index));
  } catch {
    return null;
  }
}
