import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasStringProp } from "@/lib/type-guards";
import type { AvatarFrameAccess } from "./avatar-frame-catalog";
import { BANNER_PRESETS } from "./banner-presets";

/** Story 41.11: the files of a banner uploaded from the admin; both videos for an animated one, none for a still one. */
export type ProfileBannerMedia = { image: string; webm: string | null; mp4: string | null };

/** A banner of the admin catalog. A preset of the code carries no files: the frontend draws it. */
export type ProfileBannerCatalogEntry = { key: string; label: string; access: AvatarFrameAccess; builtIn: boolean; media: ProfileBannerMedia | null };

export const PROFILE_BANNER_CATALOG_QUERY_KEY = ["profile-banner-catalog"] as const;

const ACCESSES: readonly AvatarFrameAccess[] = ["free", "members", "admins", "shop", "reward"];

function isNullableString(v: unknown): v is string | null {
  return v === null || typeof v === "string";
}

export function isProfileBannerMedia(v: unknown): v is ProfileBannerMedia {
  return typeof v === "object" && v !== null && hasStringProp(v, "image") && "webm" in v && isNullableString(v.webm) && "mp4" in v && isNullableString(v.mp4);
}

function isEntry(v: unknown): v is ProfileBannerCatalogEntry {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "key") &&
    hasStringProp(v, "label") &&
    hasStringProp(v, "access") &&
    ACCESSES.some((a) => a === v.access) &&
    hasBooleanProp(v, "builtIn") &&
    "media" in v &&
    (v.media === null || isProfileBannerMedia(v.media))
  );
}

export function isProfileBannerCatalog(v: unknown): v is { banners: ProfileBannerCatalogEntry[] } {
  return typeof v === "object" && v !== null && "banners" in v && Array.isArray(v.banners) && v.banners.every(isEntry);
}

/** An empty catalog when the API is out of reach: the presets still show. */
export async function fetchProfileBannerCatalog(): Promise<ProfileBannerCatalogEntry[]> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/profile-banners`);
    if (!res.ok) return [];
    const payload: unknown = await res.json();
    return isProfileBannerCatalog(payload) ? payload.banners : [];
  } catch {
    return [];
  }
}

export function isBannerPresetKey(key: string): boolean {
  return BANNER_PRESETS.some((preset) => preset.key === key);
}

/** The video of an animated banner, null for a still one. */
export function bannerVideo(media: ProfileBannerMedia): { webm: string; mp4: string } | null {
  return media.webm !== null && media.mp4 !== null ? { webm: media.webm, mp4: media.mp4 } : null;
}

/**
 * Why a banner is locked for this member, or null when they may pick it (stories 41.7 and 41.11). A banner the
 * catalog does not name keeps the code's rule: a shop preset is for who bought it.
 */
export function bannerLockReason(
  banner: { key: string; shop: boolean },
  access: AvatarFrameAccess | undefined,
  rights: { admin: boolean; member: boolean; owned: readonly string[] },
): string | null {
  const effective: AvatarFrameAccess = access ?? (banner.shop ? "shop" : "free");
  switch (effective) {
    case "free":
      return null;
    case "members":
      return rights.admin || rights.member ? null : "Réservée aux adhérents";
    case "admins":
      return rights.admin ? null : "Réservée aux admins";
    case "shop":
      return rights.owned.includes(banner.key) ? null : "En boutique";
    case "reward":
      return rights.owned.includes(banner.key) ? null : "À gagner (succès ou quête)";
  }
}
