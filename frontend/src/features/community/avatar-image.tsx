"use client";

import { useState } from "react";

/** The image to show: the GIF only while hovered, and never under reduced motion (story 30.42). */
export function avatarSource(src: string, animatedSrc: string | null | undefined, hovering: boolean, reducedMotion: boolean): string {
  return hovering && !reducedMotion && animatedSrc ? animatedSrc : src;
}

/**
 * A member's avatar image off the profile page (story 30.42): the still image (a GIF's first frame, served by
 * the API as `avatarUrl`), switching to the GIF (`avatarAnimatedUrl`, an admin's only) while the pointer is on
 * it. No hover on touch screens, so it stays still there.
 */
export function AvatarImage({
  src,
  animatedSrc,
  className,
  onError,
}: {
  src: string;
  animatedSrc?: string | null;
  className?: string;
  onError?: () => void;
}) {
  const [hovering, setHovering] = useState(false);
  const [reducedMotion, setReducedMotion] = useState(false);

  return (
    // eslint-disable-next-line @next/next/no-img-element -- presigned storage or external Discord/Steam URL, possibly a GIF
    <img
      alt=""
      aria-hidden="true"
      className={className}
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
    />
  );
}
