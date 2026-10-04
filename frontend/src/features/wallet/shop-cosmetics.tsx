"use client";

import { useQuery } from "@tanstack/react-query";

import { AVATAR_FRAME_CATALOG_QUERY_KEY, fetchAvatarFrameCatalog } from "@/features/community/avatar-frame-catalog";
import { MemberAvatar } from "@/features/community/member-avatar";
import { ProfileBanner } from "@/features/community/profile-banner";
import { fetchProfileBannerCatalog, PROFILE_BANNER_CATALOG_QUERY_KEY } from "@/features/community/profile-banner-catalog";
import type { ImageFraming } from "@/features/community/image-framing";
import { cosmeticLabel, type CosmeticType } from "./shop-api";

const CATALOG_STALE_TIME = 5 * 60 * 1000;

/**
 * Story 41.12: the name of each cosmetic, the admin catalogs (41.10, 41.11) included - shared with the avatars and
 * banners that already read them.
 */
export function useCosmeticLabel(): (type: CosmeticType, key: string) => string {
  const { data: frames = [] } = useQuery({ queryKey: AVATAR_FRAME_CATALOG_QUERY_KEY, queryFn: fetchAvatarFrameCatalog, staleTime: CATALOG_STALE_TIME, retry: false });
  const { data: banners = [] } = useQuery({ queryKey: PROFILE_BANNER_CATALOG_QUERY_KEY, queryFn: fetchProfileBannerCatalog, staleTime: CATALOG_STALE_TIME, retry: false });
  return (type, key) => cosmeticLabel(type, key, type === "frame" ? frames : banners);
}

export const COSMETIC_TYPE_LABELS: Record<CosmeticType, string> = { frame: "Cadre d'avatar", banner: "Bannière" };

/**
 * What an item looks like: a frame on the member's own avatar (or a neutral one), animated; a banner as on a
 * profile, its video included.
 */
export function ShopCosmeticPreview({
  type,
  cosmeticKey,
  avatarUrl = null,
  framing = null,
  name = "?",
  className = "h-36",
}: {
  type: CosmeticType;
  cosmeticKey: string;
  avatarUrl?: string | null;
  framing?: ImageFraming | null;
  name?: string;
  className?: string;
}) {
  if (type === "banner") {
    return (
      <div className={`overflow-hidden ${className}`}>
        <ProfileBanner className="size-full" presetKey={cosmeticKey} />
      </div>
    );
  }

  return (
    // No z-index here: a stacking context would cut a video frame's blend off the background.
    <div className={`flex items-center justify-center bg-[radial-gradient(circle_at_center,var(--color-surface),var(--color-background))] ${className}`}>
      <MemberAvatar animate="always" avatarUrl={avatarUrl} frame={cosmeticKey} framing={framing} name={name} size={96} sizeClassName="size-20" />
    </div>
  );
}
