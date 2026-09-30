import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";
import { isImageFraming, type ImageFraming } from "@/features/community/image-framing";

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
  // Story 30.40: the banner image the owner's status allows (null = the preset shows), its first frame when it
  // moves, whether one is set, and what the owner may upload.
  bannerImageUrl: string | null;
  bannerImageStillUrl: string | null;
  // Story 30.41: opacity (percent) of the preset laid over the banner image.
  bannerOverlay: number;
  bannerFraming: ImageFraming;
  hasCustomBanner: boolean;
  bannerUpload: { image: boolean; gif: boolean };
  avatarFrame: string | null;
  // Resolved avatar URL (custom upload presigned, else external cache); null = render the default.
  avatarUrl: string | null;
  // Story 30.42: an admin's GIF, animated on hover off the profile page.
  avatarAnimatedUrl?: string | null;
  // Story 30.43: the framing of the uploaded avatar and banner image (centred by default).
  avatarFraming: ImageFraming;
  // Whether the member has uploaded a custom avatar (vs. an external/default one).
  hasCustomAvatar: boolean;
  // Story 30.40: an admin may upload a GIF avatar.
  avatarGifAllowed: boolean;
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
  bannerOverlay: number;
  avatarFraming: ImageFraming;
  bannerFraming: ImageFraming;
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
  if (!hasNullableStringProp(v, "bannerImageUrl") || !hasNullableStringProp(v, "bannerImageStillUrl")) return false;
  if (!hasBooleanProp(v, "hasCustomBanner") || !hasBooleanProp(v, "avatarGifAllowed")) return false;
  if (!hasNumberProp(v, "bannerOverlay")) return false;
  if (!("avatarFraming" in v) || !isImageFraming(v.avatarFraming) || !("bannerFraming" in v) || !isImageFraming(v.bannerFraming)) {
    return false;
  }
  if (!("bannerUpload" in v) || typeof v.bannerUpload !== "object" || v.bannerUpload === null) return false;
  if (!hasBooleanProp(v.bannerUpload, "image") || !hasBooleanProp(v.bannerUpload, "gif")) return false;
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

/** A profile image upload (stories 30.27, 30.40): the new image URL, or the API's refusal code (null = no answer). */
export type ImageUploadResult = { ok: true; url: string } | { ok: false; code: string | null };

async function uploadProfileImage(path: string, urlField: string, file: File): Promise<ImageUploadResult> {
  try {
    const body = new FormData();
    body.append("file", file);

    const res = await apiFetch(`${env.apiBaseUrl}${path}`, { method: "POST", body });
    const json: unknown = await res.json().catch(() => null);
    if (!res.ok) {
      const error = typeof json === "object" && json !== null && "error" in json ? json.error : null;
      return { ok: false, code: typeof error === "object" && error !== null && hasStringProp(error, "code") ? error.code : null };
    }
    const data = typeof json === "object" && json !== null && "data" in json ? json.data : null;
    if (typeof data !== "object" || data === null || !hasStringProp(data, urlField)) return { ok: false, code: null };

    return { ok: true, url: data[urlField] };
  } catch {
    return { ok: false, code: null };
  }
}

/**
 * Upload a custom profile avatar (story 30.27; a GIF for an admin, story 30.40). Takes effect immediately,
 * independent of the profile save bar.
 */
export function uploadCommunityAvatar(file: File): Promise<ImageUploadResult> {
  return uploadProfileImage("/community/profile/avatar", "avatarUrl", file);
}

/** Upload a banner image (story 30.40), members and admins only; takes effect immediately. */
export function uploadCommunityBanner(file: File): Promise<ImageUploadResult> {
  return uploadProfileImage("/community/profile/banner", "bannerImageUrl", file);
}

/** Remove the banner image: the preset shows again. */
export async function removeCommunityBanner(): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/profile/banner`, { method: "DELETE" });
    return res.ok;
  } catch {
    return false;
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

/**
 * Audience a profile starts on, mirroring `Audience::DEFAULT` on the API side (story 30.28).
 *
 * Only ever shown before the API responds: the form hydrates from the server the moment the profile
 * loads. It exists so the pre-hydration flash does not display a stricter setting than the one the
 * account actually has.
 */
export const DEFAULT_AUDIENCE: (typeof AUDIENCES)[number] = "public";

export const SHOWCASE_WIDGETS = ["favorite_games", "best_runs", "most_played"] as const;

export const SHOWCASE_WIDGET_LABELS: Record<string, string> = {
  favorite_games: "Jeux favoris",
  best_runs: "Meilleures runs",
  most_played: "Les plus joués",
};
