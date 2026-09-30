"use client";

import { useState } from "react";

import { framingStyle, MIN_ZOOM, type ImageFraming } from "./image-framing";

/** The image to show: the GIF only while hovered, and never under reduced motion (story 30.42). */
export function avatarSource(src: string, animatedSrc: string | null | undefined, hovering: boolean, reducedMotion: boolean): string {
  return hovering && !reducedMotion && animatedSrc ? animatedSrc : src;
}

/**
 * A member's avatar image off the profile page (story 30.42): the still image (a GIF's first frame, served by
 * the API as `avatarUrl`), switching to the GIF (`avatarAnimatedUrl`, an admin's only) while the pointer is on
 * it. No hover on touch screens, so it stays still there.
 *
 * Story 30.43: the member's framing positions the image; a zoom needs a clipping box, so the image then sits in
 * a span that takes the classes (size, rounding) in its place.
 */
export function AvatarImage({
  src,
  animatedSrc,
  className,
  framing = null,
  onError,
}: {
  src: string;
  animatedSrc?: string | null;
  className?: string;
  framing?: ImageFraming | null;
  onError?: () => void;
}) {
  const [hovering, setHovering] = useState(false);
  const [reducedMotion, setReducedMotion] = useState(false);
  const zoomed = framing !== null && framing.zoom > MIN_ZOOM;

  const image = (
    // eslint-disable-next-line @next/next/no-img-element -- presigned storage or external Discord/Steam URL, possibly a GIF
    <img
      alt=""
      aria-hidden="true"
      className={zoomed ? "size-full object-cover" : className}
      onError={onError}
      onMouseEnter={
        animatedSrc
          ? () => {
              setReducedMotion(window.matchMedia("(prefers-reduced-motion: reduce)").matches);
              setHovering(true);
            }
          : undefined
      }
      onMouseLeave={animatedSrc ? () => setHovering(false) : undefined}
      src={avatarSource(src, animatedSrc, hovering, reducedMotion)}
      style={framingStyle(framing)}
    />
  );

  return zoomed ? <span className={`${className ?? ""} block overflow-hidden`}>{image}</span> : image;
}
