import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasStringProp } from "@/lib/type-guards";

export type EditableSocialLink = { label: string; url: string };

export type EditableFavoriteGame = {
  id: string;
  name: string;
  slug: string;
  coverImageUrl: string | null;
};

export type MyCommunityProfile = {
  slug: string | null;
  // The account name (Identity), shown as the fallback when no override is set.
  accountName: string | null;
  // Optional display-name override; null falls back to accountName.
  displayName: string | null;
  bio: string | null;
  tagline: string | null;
  pronouns: string | null;
  bannerPreset: string;
  avatarFrame: string | null;
  // Resolved avatar URL (custom upload presigned, else external cache); null = render the default.
  avatarUrl: string | null;
  // Whether the member has uploaded a custom avatar (vs. an external/default one).
  hasCustomAvatar: boolean;
  socialLinks: EditableSocialLink[];
  favoriteGames: EditableFavoriteGame[];
  audience: string;
  showcaseLayout: string[];
};

export type UpdateCommunityProfileInput = {
  displayName: string | null;
  bio: string | null;
  tagline: string | null;
  pronouns: string | null;
  bannerPreset: string;
  avatarFrame: string | null;
  audience: string;
  socialLinks: EditableSocialLink[];
  favoriteGameIds: string[];
  showcaseLayout: string[];
};

export type UpdateResult = { ok: true; profile: MyCommunityProfile } | { ok: false };

function isMyCommunityProfile(v: unknown): v is MyCommunityProfile {
  if (typeof v !== "object" || v === null) return false;
  if (
    !hasNullableStringProp(v, "bio") ||
    !hasNullableStringProp(v, "tagline") ||
    !hasNullableStringProp(v, "pronouns") ||
    !hasNullableStringProp(v, "slug") ||
    !hasNullableStringProp(v, "accountName") ||
    !hasNullableStringProp(v, "displayName")
  ) {
    return false;
  }
  if (!hasStringProp(v, "bannerPreset") || !hasStringProp(v, "audience")) return false;
  if (!hasNullableStringProp(v, "avatarUrl") || !hasBooleanProp(v, "hasCustomAvatar")) return false;
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

export async function fetchMyCommunityProfile(): Promise<MyCommunityProfile | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/profile`);
    if (!res.ok) return null;
    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json) || !isMyCommunityProfile(json.data)) {
      return null;
    }
    return json.data;
  } catch {
    return null;
  }
}

export async function updateMyCommunityProfile(input: UpdateCommunityProfileInput): Promise<UpdateResult | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/profile`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(input),
    });
    if (res.ok) {
      const json: unknown = await res.json();
      if (typeof json === "object" && json !== null && "data" in json && isMyCommunityProfile(json.data)) {
        return { ok: true, profile: json.data };
      }
      return null;
    }
    if (res.status === 422) {
      return { ok: false };
    }
    return null;
  } catch {
    return null;
  }
}

/**
 * Upload a custom profile avatar (story 30.27). Returns the new resolved (presigned) avatar URL, or null
 * on any failure (bad type/size, auth, network). The change takes effect immediately, independent of the
 * profile save bar.
 */
export async function uploadCommunityAvatar(file: File): Promise<{ avatarUrl: string } | null> {
  try {
    const body = new FormData();
    body.append("file", file);

    const res = await apiFetch(`${env.apiBaseUrl}/community/profile/avatar`, { method: "POST", body });
    if (!res.ok) return null;

    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json)) return null;
    const data = json.data;
    if (typeof data !== "object" || data === null || !hasStringProp(data, "avatarUrl")) return null;

    return { avatarUrl: data.avatarUrl };
  } catch {
    return null;
  }
}

/**
 * Remove the custom avatar, falling back to the external source/default. Returns the resolved fallback URL
 * (null when none), or null on failure - callers treat both as "now using the default/external".
 */
export async function removeCommunityAvatar(): Promise<{ avatarUrl: string | null } | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/profile/avatar`, { method: "DELETE" });
    if (!res.ok) return null;

    const json: unknown = await res.json();
    if (typeof json !== "object" || json === null || !("data" in json)) return null;
    const data = json.data;
    if (typeof data !== "object" || data === null || !hasNullableStringProp(data, "avatarUrl")) return null;

    return { avatarUrl: data.avatarUrl };
  } catch {
    return null;
  }
}

export const AUDIENCES = ["public", "members", "friends"] as const;

export const SHOWCASE_WIDGETS = ["favorite_games", "best_runs", "most_played"] as const;

export const SHOWCASE_WIDGET_LABELS: Record<string, string> = {
  favorite_games: "Jeux favoris",
  best_runs: "Meilleures runs",
  most_played: "Les plus joués",
};
