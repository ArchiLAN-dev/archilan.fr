import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

export type ActivityActor = {
  slug: string;
  displayName: string | null;
  avatarUrl: string | null;
  playing: boolean;
};

export type ActivityItem = {
  type: "run_finished" | "friendship";
  occurredAt: string;
  game: string | null;
  event: string | null;
  sessionId: string | null;
  withSlug: string | null;
  withName: string | null;
  actor: ActivityActor | null;
  kudosTargetType: string | null;
  kudosTargetId: string | null;
  kudosCount: number;
  viewerHasKudos: boolean;
};

function isActor(v: unknown): v is ActivityActor {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "slug") &&
    hasNullableStringProp(v, "displayName") &&
    hasNullableStringProp(v, "avatarUrl") &&
    hasBooleanProp(v, "playing")
  );
}

function isActivityItem(v: unknown): v is ActivityItem {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "type") || (v.type !== "run_finished" && v.type !== "friendship")) return false;
  if (!hasStringProp(v, "occurredAt")) return false;
  for (const key of ["game", "event", "sessionId", "withSlug", "withName"] as const) {
    if (!hasNullableStringProp(v, key)) return false;
  }
  if ("actor" in v && v.actor !== null && !isActor(v.actor)) return false;
  if (!hasNullableStringProp(v, "kudosTargetType") || !hasNullableStringProp(v, "kudosTargetId")) return false;
  return hasNumberProp(v, "kudosCount") && hasBooleanProp(v, "viewerHasKudos");
}

function normalize(item: ActivityItem & { actor?: ActivityActor | null }): ActivityItem {
  return { ...item, actor: item.actor ?? null };
}

async function fetchItems(url: string): Promise<ActivityItem[] | null> {
  try {
    const res = await apiFetch(url);
    if (!res.ok) return null;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json) || !Array.isArray(json.data)) return null;
    if (!json.data.every(isActivityItem)) return null;
    return json.data.map(normalize);
  } catch {
    return null;
  }
}

export function fetchProfileActivity(slug: string, limit = 20): Promise<ActivityItem[] | null> {
  return fetchItems(`${env.apiBaseUrl}/community/profiles/${encodeURIComponent(slug)}/activity?limit=${limit}`);
}

export function fetchFriendsFeed(limit = 30): Promise<ActivityItem[] | null> {
  return fetchItems(`${env.apiBaseUrl}/community/feed?limit=${limit}`);
}
