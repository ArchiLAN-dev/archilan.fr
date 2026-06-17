import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasNullableStringProp, hasStringProp } from "@/lib/type-guards";

export type EditableSocialLink = { label: string; url: string };

export type EditableFavoriteGame = {
  id: string;
  name: string;
  slug: string;
  coverImageUrl: string | null;
};

export type MyCommunityProfile = {
  slug: string | null;
  displayName: string | null;
  bio: string | null;
  tagline: string | null;
  pronouns: string | null;
  bannerPreset: string;
  socialLinks: EditableSocialLink[];
  favoriteGames: EditableFavoriteGame[];
  audience: string;
  showcaseLayout: string[];
};

export type UpdateCommunityProfileInput = {
  bio: string | null;
  tagline: string | null;
  pronouns: string | null;
  bannerPreset: string;
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
    !hasNullableStringProp(v, "displayName")
  ) {
    return false;
  }
  if (!hasStringProp(v, "bannerPreset") || !hasStringProp(v, "audience")) return false;
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

export const BANNER_PRESETS = ["default", "sunset", "forest", "arcade", "midnight", "aurora"] as const;
export const AUDIENCES = ["public", "members", "friends"] as const;

export const SHOWCASE_WIDGETS = [
  "favorite_games",
  "featured_achievements",
  "best_runs",
  "most_played",
] as const;

export const SHOWCASE_WIDGET_LABELS: Record<string, string> = {
  favorite_games: "Jeux favoris",
  featured_achievements: "Succès en vedette",
  best_runs: "Meilleures runs",
  most_played: "Les plus joués",
};
