"use client";

import { useContext, type CSSProperties, type ReactNode } from "react";
import { QueryClientContext, useQuery } from "@tanstack/react-query";

import { cn } from "@/lib/utils";
import { AvatarFrameVideoLayer } from "./avatar-frame-video";
import { AVATAR_FRAME_CATALOG_QUERY_KEY, fetchAvatarFrameCatalog } from "./avatar-frame-catalog";
import styles from "./avatar-frame.module.css";

type Props = {
  frameKey: string;
  className?: string;
  style?: CSSProperties;
  preview?: boolean;
  animated?: boolean;
  children: ReactNode;
};

/**
 * Story 41.10: a frame the code catalog does not know, read from the admin catalog. Rendered on the client: the
 * server shows the avatar without it, the frame appears once the page is live (its video loads there anyway). Outside
 * a query client (an overlay, a test), the avatar stays plain.
 */
export function CatalogAvatarFrame(props: Props) {
  const client = useContext(QueryClientContext);
  if (client === undefined) return <PlainFrame className={props.className} style={props.style}>{props.children}</PlainFrame>;

  return <LoadedCatalogFrame {...props} />;
}

function LoadedCatalogFrame({ frameKey, className, style, preview = false, animated = true, children }: Props) {
  const { data } = useQuery({
    queryKey: AVATAR_FRAME_CATALOG_QUERY_KEY,
    queryFn: fetchAvatarFrameCatalog,
    staleTime: 5 * 60 * 1000,
    retry: false,
  });
  const video = data?.find((frame) => frame.key === frameKey)?.video ?? null;
  const size = className ?? "";

  if (video === null) return <PlainFrame className={className} style={style}>{children}</PlainFrame>;

  if (preview) {
    return (
      <div className={cn(styles.videoPreview, size)} style={style}>
        <div className={styles.videoPreviewInner}>{children}</div>
        <AvatarFrameVideoLayer className={styles.videoPreviewLayer} playing={animated} video={video} />
      </div>
    );
  }

  return (
    <div className={cn(styles.videoFrame, size)} style={style}>
      <div className={styles.videoInner}>{children}</div>
      <AvatarFrameVideoLayer className={styles.videoLayer} playing={animated} video={video} />
    </div>
  );
}

function PlainFrame({ className, style, children }: { className?: string; style?: CSSProperties; children: ReactNode }) {
  return (
    <div className={cn(styles.plain, className ?? "")} style={style}>
      {children}
    </div>
  );
}
