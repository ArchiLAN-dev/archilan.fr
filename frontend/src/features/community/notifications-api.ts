import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

export type NotificationActor = { slug: string; displayName: string | null; avatarUrl: string | null };

export type NotificationItem = {
  id: string;
  type: string;
  createdAt: string;
  read: boolean;
  actor: NotificationActor | null;
  data: Record<string, unknown>;
};

export type NotificationsResult = { items: NotificationItem[]; unreadCount: number };

export type NotificationStreamToken = { token: string; hubUrl: string; topic: string };

function isActor(v: unknown): v is NotificationActor {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "slug") &&
    hasNullableStringProp(v, "displayName") &&
    hasNullableStringProp(v, "avatarUrl")
  );
}

function isNotificationItem(v: unknown): v is NotificationItem {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "id") || !hasStringProp(v, "type") || !hasStringProp(v, "createdAt")) return false;
  if (!hasBooleanProp(v, "read")) return false;
  if (!("actor" in v) || (v.actor !== null && !isActor(v.actor))) return false;
  return "data" in v && typeof v.data === "object" && v.data !== null;
}

export async function fetchNotifications(limit = 30): Promise<NotificationsResult | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/notifications?limit=${limit}`);
    if (!res.ok) return null;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json) || !Array.isArray(json.data)) return null;
    if (!json.data.every(isNotificationItem)) return null;

    let unreadCount = 0;
    const meta: unknown = "meta" in json ? json.meta : null;
    if (typeof meta === "object" && meta !== null && hasNumberProp(meta, "unreadCount")) {
      unreadCount = meta.unreadCount;
    }

    return { items: json.data, unreadCount };
  } catch {
    return null;
  }
}

export async function markNotificationRead(id: string): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/notifications/${encodeURIComponent(id)}/read`, {
      method: "POST",
    });
    return res.ok;
  } catch {
    return false;
  }
}

export async function markAllNotificationsRead(): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/notifications/read-all`, { method: "POST" });
    return res.ok;
  } catch {
    return false;
  }
}

export async function fetchNotificationStreamToken(): Promise<NotificationStreamToken | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/realtime/notifications-token`);
    if (!res.ok) return null;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json)) return null;
    const data: unknown = json.data;
    if (typeof data !== "object" || data === null) return null;
    if (!hasStringProp(data, "token") || !hasStringProp(data, "hubUrl") || !hasStringProp(data, "topic")) return null;
    return { token: data.token, hubUrl: data.hubUrl, topic: data.topic };
  } catch {
    return null;
  }
}
