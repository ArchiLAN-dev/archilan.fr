"use client";

import { useState } from "react";
import { AvatarImage } from "./avatar-image";

type Props = {
  avatarUrl: string | null;
  /** Story 30.42: an admin's GIF, animated on hover. */
  animatedUrl?: string | null;
  name: string;
  /** Tailwind size class, e.g. "size-10". */
  size?: string;
};

/**
 * A member's avatar with the project-wide fallback: a snapshotted Discord/Steam URL can 404 later, so a
 * load error degrades to the initial rather than a broken image.
 */
export function MemberAvatar({ avatarUrl, animatedUrl = null, name, size = "size-10" }: Props) {
  const [failed, setFailed] = useState(false);
  const initial = name.slice(0, 1).toUpperCase();

  if (avatarUrl !== null && !failed) {
    return (
      <AvatarImage
        animatedSrc={animatedUrl}
        className={`${size} shrink-0 rounded-full bg-surface object-cover`}
        onError={() => setFailed(true)}
        src={avatarUrl}
      />
    );
  }

  return (
    <span
      aria-hidden="true"
      className={`${size} flex shrink-0 items-center justify-center rounded-full bg-accent/15 text-sm font-bold text-accent-text`}
    >
      {initial}
    </span>
  );
}
