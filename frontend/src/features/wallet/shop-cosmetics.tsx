"use client";

import { useQuery } from "@tanstack/react-query";

import { AVATAR_FRAME_CATALOG_QUERY_KEY, fetchAvatarFrameCatalog } from "@/features/community/avatar-frame-catalog";
import { MemberAvatar } from "@/features/community/member-avatar";
import { ProfileBanner } from "@/features/community/profile-banner";
import { fetchProfileBannerCatalog, PROFILE_BANNER_CATALOG_QUERY_KEY } from "@/features/community/profile-banner-catalog";
import { ProfileTitleBadge } from "@/features/community/profile-title-badge";
import type { NameColorStyle } from "@/features/community/name-colors";
import { TitledName } from "@/features/community/titled-name";
import { fetchProfileTitleCatalog, PROFILE_TITLE_CATALOG_QUERY_KEY, badgeOf, type TitleBadge } from "@/features/community/profile-title-catalog";
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
  const { data: titles = [] } = useQuery({ queryKey: PROFILE_TITLE_CATALOG_QUERY_KEY, queryFn: fetchProfileTitleCatalog, staleTime: CATALOG_STALE_TIME, retry: false });
  return (type, key) => cosmeticLabel(type, key, type === "frame" ? frames : type === "banner" ? banners : titles);
}

/** Story 41.27: a title's badge (rarity, icon) from the catalog, null for a key it does not know. */
export function useTitleBadge(): (key: string) => TitleBadge | null {
  const { data: titles = [] } = useQuery({ queryKey: PROFILE_TITLE_CATALOG_QUERY_KEY, queryFn: fetchProfileTitleCatalog, staleTime: CATALOG_STALE_TIME, retry: false });
  return (key) => {
    const title = titles.find((candidate) => candidate.key === key);
    return title ? badgeOf(title) : null;
  };
}

export const COSMETIC_TYPE_LABELS: Record<CosmeticType, string> = { frame: "Cadre d'avatar", banner: "Bannière", title: "Titre de profil", color: "Couleur de pseudo" };

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
  label = null,
  title = null,
}: {
  type: CosmeticType;
  cosmeticKey: string;
  /** Story 41.22: a title shows its text under the member's name. */
  label?: string | null;
  /** Story 41.27: the title's badge, its rarity and icon (a plain one when unknown). */
  title?: TitleBadge | null;
  avatarUrl?: string | null;
  framing?: ImageFraming | null;
  name?: string;
  className?: string;
}) {
  if (type === "color") {
    // Story 41.23: the member's own name in the colour.
    return (
      <div className={`flex flex-col items-center justify-center gap-2 bg-[radial-gradient(circle_at_center,var(--color-surface),var(--color-background))] ${className}`}>
        <MemberAvatar avatarUrl={avatarUrl} framing={framing} name={name} size={64} sizeClassName="size-14" />
        <span className="font-heading text-lg font-bold">
          <TitledName style={`color-${cosmeticKey}` as NameColorStyle} variant="card">
            {name}
          </TitledName>
        </span>
      </div>
    );
  }

  if (type === "title") {
    return (
      <div className={`flex flex-col items-center justify-center gap-2 bg-[radial-gradient(circle_at_center,var(--color-surface),var(--color-background))] ${className}`}>
        <MemberAvatar avatarUrl={avatarUrl} framing={framing} name={name} size={64} sizeClassName="size-14" />
        <span className="text-sm font-semibold text-foreground">{name}</span>
        <ProfileTitleBadge title={title ?? { label: label ?? cosmeticKey, rarity: "common", icon: null, access: "shop" }} />
      </div>
    );
  }

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
