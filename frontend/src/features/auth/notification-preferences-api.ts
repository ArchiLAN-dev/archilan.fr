import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasStringProp } from "@/lib/type-guards";

/** Story 43.11b: where a type of notification reaches the member. */
export type NotificationChannel = "bell_push" | "bell" | "none";

export type NotificationPreference = { type: string; channel: NotificationChannel };

export const NOTIFICATION_CHANNELS: NotificationChannel[] = ["bell_push", "bell", "none"];

function isPreference(v: unknown): v is NotificationPreference {
  if (typeof v !== "object" || v === null || !hasStringProp(v, "type") || !hasStringProp(v, "channel")) return false;
  const channel = v.channel;
  return NOTIFICATION_CHANNELS.some((c) => c === channel);
}

async function preferencesOf(res: Response): Promise<NotificationPreference[] | null> {
  if (!res.ok) return null;
  const json: unknown = await res.json();
  const data = typeof json === "object" && json !== null && "data" in json ? json.data : null;
  return Array.isArray(data) && data.every(isPreference) ? data : null;
}

/** The configurable types and the member's choice for each. Null on failure. */
export async function fetchNotificationPreferences(): Promise<NotificationPreference[] | null> {
  try {
    return await preferencesOf(await apiFetch(`${env.apiBaseUrl}/community/notification-preferences`));
  } catch {
    return null;
  }
}

/** The settings as they now stand, null on failure. */
export async function chooseNotificationChannel(type: string, channel: NotificationChannel): Promise<NotificationPreference[] | null> {
  try {
    return await preferencesOf(
      await apiFetch(`${env.apiBaseUrl}/community/notification-preferences/${encodeURIComponent(type)}`, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ channel }),
      }),
    );
  } catch {
    return null;
  }
}
