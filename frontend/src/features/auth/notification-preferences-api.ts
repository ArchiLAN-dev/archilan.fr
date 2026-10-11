import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasStringProp } from "@/lib/type-guards";

/** Story 43.11b: where a type of notification reaches the member. */
export type NotificationChannel = "bell_push" | "bell" | "none";

/** Story 40.6: `discord` is whether the type also goes to Discord, null while the member's Discord is not linked. */
export type NotificationPreference = { type: string; channel: NotificationChannel; discord: boolean | null };

export const NOTIFICATION_CHANNELS: NotificationChannel[] = ["bell_push", "bell", "none"];

function isPreference(v: unknown): v is NotificationPreference {
  if (typeof v !== "object" || v === null || !hasStringProp(v, "type") || !hasStringProp(v, "channel")) return false;
  const channel = v.channel;
  const discord = "discord" in v ? v.discord : null;
  return NOTIFICATION_CHANNELS.some((c) => c === channel) && (discord === null || typeof discord === "boolean");
}

async function preferencesOf(res: Response): Promise<NotificationPreference[] | null> {
  if (!res.ok) return null;
  const json: unknown = await res.json();
  const data = typeof json === "object" && json !== null && "data" in json ? json.data : null;
  if (!Array.isArray(data) || !data.every(isPreference)) return null;
  // An API from before story 40.6 sends no `discord`: nothing to offer then.
  return data.map((preference) => ({ ...preference, discord: preference.discord ?? null }));
}

/** The configurable types and the member's choice for each. Null on failure. */
export async function fetchNotificationPreferences(): Promise<NotificationPreference[] | null> {
  try {
    return await preferencesOf(await apiFetch(`${env.apiBaseUrl}/community/notification-preferences`));
  } catch {
    return null;
  }
}

/** Story 40.6: also on Discord, or not, for one type. The settings as they now stand, null on failure. */
export async function chooseNotificationDiscord(type: string, discord: boolean): Promise<NotificationPreference[] | null> {
  try {
    return await preferencesOf(
      await apiFetch(`${env.apiBaseUrl}/community/notification-preferences/${encodeURIComponent(type)}`, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ discord }),
      }),
    );
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
