import { cache } from "react";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

export type PlayerStats = {
  runsParticipated: number;
  goalCompletions: number;
  goalCompletionRate: number;
  totalChecksDone: number;
  totalItemsReceived: number;
};

export type ProfileSocialLink = { label: string; url: string };

export type ProfileFavoriteGame = {
  id: string;
  name: string;
  slug: string;
  coverImageUrl: string | null;
};

export type ProfileCustomization = {
  bio: string | null;
  tagline: string | null;
  pronouns: string | null;
  bannerPreset: string;
  avatarFrame: string | null;
  socialLinks: ProfileSocialLink[];
  favoriteGames: ProfileFavoriteGame[];
  showcaseLayout: string[];
};

export type Achievement = {
  key: string;
  name: string;
  description: string;
  unlocked: boolean;
  unlockedAt: string | null;
  grantId: string | null;
  kudosCount: number;
};

export type ProfileLevel = {
  level: number;
  xp: number;
  xpIntoLevel: number;
  xpForNextLevel: number;
};

export type ProfilePresence = { playing: boolean; sessionId: string | null; game: string | null };

/** Public recognition badges: active membership (live lookup) and admin role. */
export type ProfileBadges = { member: boolean; admin: boolean };

export type PlayerProfile = {
  slug: string;
  displayName: string | null;
  joinedAt: string;
  avatarUrl: string | null;
  audience: string;
  badges: ProfileBadges;
  level: ProfileLevel;
  achievements: Achievement[];
  presence: ProfilePresence;
  customization: ProfileCustomization | null;
  stats: PlayerStats;
};

const DEFAULT_LEVEL: ProfileLevel = { level: 0, xp: 0, xpIntoLevel: 0, xpForNextLevel: 100 };
const OFFLINE: ProfilePresence = { playing: false, sessionId: null, game: null };
const NO_BADGES: ProfileBadges = { member: false, admin: false };

function parseBadges(v: unknown): ProfileBadges {
  if (typeof v !== "object" || v === null) return NO_BADGES;
  return {
    member: hasBooleanProp(v, "member") && v.member,
    admin: hasBooleanProp(v, "admin") && v.admin,
  };
}

function parsePresence(v: unknown): ProfilePresence {
  if (typeof v !== "object" || v === null || !hasBooleanProp(v, "playing")) return OFFLINE;
  return {
    playing: v.playing,
    sessionId: hasNullableStringProp(v, "sessionId") ? v.sessionId : null,
    game: hasNullableStringProp(v, "game") ? v.game : null,
  };
}

export type RunHistoryEntry = {
  sessionId: string;
  eventName: string;
  finishedAt: string | null;
  game: string;
  checksDone: number;
  itemsReceived: number;
  goalReachedAt: string | null;
  wasReleased: boolean;
  isInvalidated: boolean;
};

export type PlayerHistory = {
  data: RunHistoryEntry[];
  meta: { page: number; limit: number; total: number };
};

function isPlayerStats(v: unknown): v is PlayerStats {
  if (typeof v !== "object" || v === null) return false;
  return (
    hasNumberProp(v, "runsParticipated") &&
    hasNumberProp(v, "goalCompletions") &&
    hasNumberProp(v, "goalCompletionRate") &&
    hasNumberProp(v, "totalChecksDone") &&
    hasNumberProp(v, "totalItemsReceived")
  );
}

function isProfileCustomization(v: unknown): v is ProfileCustomization {
  if (typeof v !== "object" || v === null) return false;
  if (!hasNullableStringProp(v, "bio") || !hasNullableStringProp(v, "tagline") || !hasNullableStringProp(v, "pronouns")) {
    return false;
  }
  if (!hasStringProp(v, "bannerPreset")) return false;
  if ("avatarFrame" in v && v.avatarFrame !== null && typeof v.avatarFrame !== "string") return false;
  if (!("socialLinks" in v) || !Array.isArray(v.socialLinks)) return false;
  if (!v.socialLinks.every((l) => hasStringProp(l, "label") && hasStringProp(l, "url"))) return false;
  if (!("favoriteGames" in v) || !Array.isArray(v.favoriteGames)) return false;
  if (!v.favoriteGames.every((g) => hasStringProp(g, "id") && hasStringProp(g, "name") && hasStringProp(g, "slug"))) {
    return false;
  }
  if (!("showcaseLayout" in v) || !Array.isArray(v.showcaseLayout)) return false;
  return v.showcaseLayout.every((w) => typeof w === "string");
}

function isProfileLevel(v: unknown): v is ProfileLevel {
  if (typeof v !== "object" || v === null) return false;
  return (
    hasNumberProp(v, "level") &&
    hasNumberProp(v, "xp") &&
    hasNumberProp(v, "xpIntoLevel") &&
    hasNumberProp(v, "xpForNextLevel")
  );
}

function isAchievement(v: unknown): v is Achievement {
  if (typeof v !== "object" || v === null) return false;
  return (
    hasStringProp(v, "key") &&
    hasStringProp(v, "name") &&
    hasStringProp(v, "description") &&
    hasBooleanProp(v, "unlocked") &&
    hasNullableStringProp(v, "unlockedAt") &&
    hasNullableStringProp(v, "grantId") &&
    hasNumberProp(v, "kudosCount")
  );
}

function isPlayerProfilePayload(payload: unknown): payload is { data: PlayerProfile } {
  if (typeof payload !== "object" || payload === null) return false;
  if (!("data" in payload) || typeof payload.data !== "object" || payload.data === null) return false;
  const data = payload.data;
  if (!hasStringProp(data, "slug")) return false;
  if (!("displayName" in data) || (data.displayName !== null && typeof data.displayName !== "string")) return false;
  if (!hasStringProp(data, "joinedAt")) return false;
  if ("avatarUrl" in data && data.avatarUrl !== null && typeof data.avatarUrl !== "string") return false;
  if ("customization" in data && data.customization !== null && !isProfileCustomization(data.customization)) {
    return false;
  }
  if ("achievements" in data && (!Array.isArray(data.achievements) || !data.achievements.every(isAchievement))) {
    return false;
  }
  if (!("stats" in data)) return false;
  return isPlayerStats(data.stats);
}

function isRunHistoryEntry(v: unknown): v is RunHistoryEntry {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "sessionId")) return false;
  if (!hasStringProp(v, "eventName")) return false;
  if (!("finishedAt" in v) || (v.finishedAt !== null && typeof v.finishedAt !== "string")) return false;
  if (!hasStringProp(v, "game")) return false;
  if (!hasNumberProp(v, "checksDone")) return false;
  if (!hasNumberProp(v, "itemsReceived")) return false;
  if (!("goalReachedAt" in v) || (v.goalReachedAt !== null && typeof v.goalReachedAt !== "string")) return false;
  if (!hasBooleanProp(v, "wasReleased")) return false;
  return hasBooleanProp(v, "isInvalidated");
}

function isPlayerHistoryPayload(payload: unknown): payload is PlayerHistory {
  if (typeof payload !== "object" || payload === null) return false;
  if (!("data" in payload) || !Array.isArray(payload.data)) return false;
  return payload.data.every(isRunHistoryEntry);
}

export const getPlayerProfile = cache(async (slug: string): Promise<PlayerProfile | null> => {
  try {
    const response = await fetch(`${env.apiBaseUrl}/community/profiles/${encodeURIComponent(slug)}`, {
      cache: "no-store",
    });

    if (!response.ok) {
      return null;
    }

    const payload: unknown = await response.json();
    if (!isPlayerProfilePayload(payload)) {
      return null;
    }

    const data = payload.data;
    return {
      slug: data.slug,
      displayName: data.displayName,
      joinedAt: data.joinedAt,
      avatarUrl: data.avatarUrl ?? null,
      audience: typeof data.audience === "string" ? data.audience : "members",
      badges: parseBadges("badges" in data ? data.badges : null),
      level: isProfileLevel(data.level) ? data.level : DEFAULT_LEVEL,
      achievements: Array.isArray(data.achievements) ? data.achievements : [],
      presence: parsePresence("presence" in data ? data.presence : null),
      customization: data.customization ?? null,
      stats: data.stats,
    };
  } catch {
    return null;
  }
});

export const getPlayerHistory = cache(async (slug: string): Promise<PlayerHistory | null> => {
  try {
    const response = await fetch(
      `${env.apiBaseUrl}/players/${encodeURIComponent(slug)}/history?limit=100`,
      { cache: "no-store" },
    );

    if (!response.ok) {
      return null;
    }

    const payload: unknown = await response.json();
    if (!isPlayerHistoryPayload(payload)) {
      return null;
    }

    return payload;
  } catch {
    return null;
  }
});
