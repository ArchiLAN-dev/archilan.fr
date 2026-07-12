import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp } from "@/lib/type-guards";

export type TwitchLiveStatus = {
    live: boolean;
    viewerCount: number | null;
};

/**
 * Live status of the association's Twitch channel. Returns null on network error,
 * non-OK response or invalid payload.
 */
export async function fetchTwitchLiveStatus(signal?: AbortSignal): Promise<TwitchLiveStatus | null> {
    try {
        const res = await apiFetch(`${env.apiBaseUrl}/live/status`, { signal });
        if (!res.ok) return null;
        const payload: unknown = await res.json();
        if (!isTwitchLiveStatusPayload(payload)) return null;
        const viewerCount: unknown = Reflect.get(payload.data, "viewerCount");
        return {
            live: payload.data.live,
            viewerCount: typeof viewerCount === "number" ? viewerCount : null,
        };
    } catch {
        return null;
    }
}

function isTwitchLiveStatusPayload(v: unknown): v is { data: { live: boolean } } {
    if (typeof v !== "object" || v === null || !("data" in v)) return false;
    const data: unknown = Reflect.get(v, "data");
    return typeof data === "object" && data !== null && hasBooleanProp(data, "live");
}
