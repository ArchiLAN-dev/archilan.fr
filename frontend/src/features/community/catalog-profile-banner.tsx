"use client";

import { useContext, type ComponentProps } from "react";
import { QueryClientContext, useQuery } from "@tanstack/react-query";

import { ProfileBanner } from "./profile-banner";
import { fetchProfileBannerCatalog, PROFILE_BANNER_CATALOG_QUERY_KEY } from "./profile-banner-catalog";

type Props = ComponentProps<typeof ProfileBanner>;

/**
 * Story 41.11: a banner the presets of the code do not know, read from the admin catalog. Rendered on the client:
 * the server shows the default preset, the banner appears once the page is live. Outside a query client (a test),
 * the default preset stays.
 */
export function CatalogProfileBanner(props: Props) {
  const client = useContext(QueryClientContext);
  if (client === undefined) return <ProfileBanner {...props} media={null} presetKey="default" />;

  return <LoadedCatalogBanner {...props} />;
}

function LoadedCatalogBanner(props: Props) {
  const { data } = useQuery({
    queryKey: PROFILE_BANNER_CATALOG_QUERY_KEY,
    queryFn: fetchProfileBannerCatalog,
    staleTime: 5 * 60 * 1000,
    retry: false,
  });
  const media = data?.find((banner) => banner.key === props.presetKey)?.media ?? null;

  return <ProfileBanner {...props} media={media} presetKey={media === null ? "default" : props.presetKey} />;
}
