"use client";

import { useQuery } from "@tanstack/react-query";
import { Megaphone } from "lucide-react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchShopAnnouncement, timeLeftLabel, type ShopAnnouncement } from "@/features/wallet/shop-api";

/**
 * Story 41.14: the promotion running on the HelloAsso items, as the admin announced it. Nothing while there is none.
 * The time left is counted from when the banner was read (`dataUpdatedAt`), so the render stays pure (AC-HK3).
 */
export function ShopAnnouncementBanner() {
  const { data, dataUpdatedAt } = useQuery({
    queryKey: ["shop-announcement"],
    queryFn: () => fetchShopAnnouncement(),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  return data ? <AnnouncementView announcement={data} now={dataUpdatedAt} /> : null;
}

export function AnnouncementView({ announcement, now }: { announcement: ShopAnnouncement; now: number }) {
  return (
    <p className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-danger/40 bg-danger/10 px-4 py-3 text-sm" role="status">
      <Megaphone aria-hidden className="size-4 shrink-0 text-danger" />
      <span className="font-semibold text-foreground">{announcement.message}</span>
      <span className="font-medium text-danger">{timeLeftLabel(announcement.endsAt, now)}</span>
    </p>
  );
}
