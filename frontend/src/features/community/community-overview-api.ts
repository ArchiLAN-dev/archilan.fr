import { env } from "@/lib/env";
import { hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

export type PlayingNowEntry = {
  slug: string;
  displayName: string | null;
  avatarUrl: string | null;
  /** null when the viewer may not know what is being played (unpublished personal run). */
  game: string | null;
};

export type RecentAchievement = {
  achievementKey: string;
  name: string;
  imageUrl: string | null;
  unlockedAt: string;
  slug: string;
  displayName: string | null;
  avatarUrl: string | null;
};

export type CommunityOverview = {
  memberCount: number;
  playingNow: PlayingNowEntry[];
  recentAchievements: RecentAchievement[];
};

function isPlayingNowEntry(v: unknown): v is PlayingNowEntry {
  if (typeof v !== "object" || v === null) return false;
  return (
    hasStringProp(v, "slug") &&
    hasNullableStringProp(v, "displayName") &&
    hasNullableStringProp(v, "avatarUrl") &&
    hasNullableStringProp(v, "game")
  );
}

function isRecentAchievement(v: unknown): v is RecentAchievement {
  if (typeof v !== "object" || v === null) return false;
  return (
    hasStringProp(v, "achievementKey") &&
    hasStringProp(v, "name") &&
    hasNullableStringProp(v, "imageUrl") &&
    hasStringProp(v, "unlockedAt") &&
    hasStringProp(v, "slug") &&
    hasNullableStringProp(v, "displayName") &&
    hasNullableStringProp(v, "avatarUrl")
  );
}

function isOverviewPayload(payload: unknown): payload is { data: CommunityOverview } {
  if (typeof payload !== "object" || payload === null) return false;
  if (!("data" in payload) || typeof payload.data !== "object" || payload.data === null) return false;
  const data = payload.data;
  if (!hasNumberProp(data, "memberCount")) return false;
  if (!("playingNow" in data) || !Array.isArray(data.playingNow)) return false;
  if (!("recentAchievements" in data) || !Array.isArray(data.recentAchievements)) return false;
  return data.playingNow.every(isPlayingNowEntry) && data.recentAchievements.every(isRecentAchievement);
}

/**
 * The hub's own read (story 30.38). Server-side and anonymous: the page is built for the logged-out
 * visitor, and the per-viewer parts of the payload (a private run naming its game) are additive, not
 * something the shell depends on.
 */
export async function fetchCommunityOverview(): Promise<CommunityOverview | null> {
  try {
    const response = await fetch(`${env.apiBaseUrl}/community/overview`, { cache: "no-store" });
    if (!response.ok) return null;
    const payload: unknown = await response.json();
    if (!isOverviewPayload(payload)) return null;
    return payload.data;
  } catch {
    return null;
  }
}
