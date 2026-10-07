"use client";

import { useState, type CSSProperties } from "react";

import { cn } from "@/lib/utils";

import { AvatarFrame } from "./avatar-frame";
import { useMotionAllowed } from "./avatar-frame-video";
import { getAvatarFrame } from "./avatar-frames";
import { avatarSource } from "./avatar-image";
import { framingStyle, type ImageFraming } from "./image-framing";

/** The profile page's avatar size: frame lengths are drawn for it and scale from it (story 30.47). */
const REFERENCE_SIZE = 112;

type Props = {
  avatarUrl: string | null;
  /** Story 30.42: an admin's GIF, animated on hover off the profile page. */
  avatarAnimatedUrl?: string | null;
  /** Story 30.43: the framing of an uploaded photo. */
  framing?: ImageFraming | null;
  /** Story 30.47: the member's avatar frame (a key, or null for none). */
  frame?: string | null;
  name: string;
  /** Size in px: sets the box and the frame's proportions. */
  size: number;
  /**
   * Responsive size classes (e.g. "size-24 sm:size-28") replacing the fixed `size` box; `size` still sets the
   * frame's proportions (pass the largest).
   */
  sizeClassName?: string;
  /**
   * `always`: the frame and an animated photo move for good (the profile page). `hover` (default): still until the
   * avatar is hovered, like an admin's GIF off the profile page (story 30.42). Reduced motion always wins.
   */
  animate?: "always" | "hover";
  /**
   * false: a video frame stays on its still even when hovered. For a surface whose parent makes a stacking context
   * with a see-through background (`card-glow`'s backdrop blur): the screen blend would show the video's black there.
   */
  hoverVideo?: boolean;
  className?: string;
};

/**
 * A member's profile picture, the same everywhere (story 30.47): the rounded square of the profile page, inside the
 * member's frame, with the default-avatar fallback (initials on a per-member gradient) for no photo or a photo that
 * fails to load. A frame's effect may overflow the box around it (it never changes the layout).
 */
export function MemberAvatar({
  avatarUrl,
  avatarAnimatedUrl = null,
  framing = null,
  frame = null,
  name,
  size,
  sizeClassName,
  animate = "hover",
  hoverVideo = true,
  className,
}: Props) {
  const [hovered, setHovered] = useState(false);
  const motion = useMotionAllowed();
  const animated = animate === "always" || hovered;
  const frameAnimated = animated && (hoverVideo || animate === "always" || getAvatarFrame(frame)?.variant !== "video");

  const style = {
    ...(sizeClassName ? {} : { width: size, height: size }),
    "--s": size / REFERENCE_SIZE,
  } as CSSProperties;

  return (
    <AvatarFrame
      animated={frameAnimated}
      className={cn("shrink-0", sizeClassName, className)}
      frameKey={frame}
      style={style}
    >
      <span className="block size-full" onMouseEnter={() => setHovered(true)} onMouseLeave={() => setHovered(false)}>
        <AvatarContent
          avatarAnimatedUrl={avatarAnimatedUrl}
          avatarUrl={avatarUrl}
          framing={framing}
          name={name}
          playing={animated && motion}
          size={size}
        />
      </span>
    </AvatarFrame>
  );
}

/**
 * The photo itself (its GIF while `playing`), or the member's default avatar: initials on a gradient picked from
 * the name (story 30.27), so the same member shows the same default everywhere. A photo that fails to load (a
 * snapshotted Discord/Steam URL can 404 later) falls back to the default, never a broken image.
 */
export function AvatarContent({
  avatarUrl,
  avatarAnimatedUrl = null,
  name,
  framing = null,
  playing = false,
  size,
}: {
  avatarUrl: string | null;
  avatarAnimatedUrl?: string | null;
  name: string;
  framing?: ImageFraming | null;
  playing?: boolean;
  /** Size in px, for the initials' type size. */
  size: number;
}) {
  const [failed, setFailed] = useState(false);

  if (avatarUrl !== null && !failed) {
    return (
      // eslint-disable-next-line @next/next/no-img-element -- presigned storage or external Discord/Steam URL, possibly a GIF
      <img
        alt={name}
        className="size-full bg-surface object-cover"
        onError={() => setFailed(true)}
        src={avatarSource(avatarUrl, avatarAnimatedUrl, playing, false)}
        style={framingStyle(framing)}
      />
    );
  }

  return (
    <span
      aria-hidden
      className={`flex size-full items-center justify-center bg-gradient-to-br ${defaultVariant(name)} font-heading font-bold text-white`}
      style={{ fontSize: Math.max(10, Math.round(size * 0.3)) }}
    >
      {initials(name)}
    </span>
  );
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return "?";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

// Curated set of default-avatar backgrounds (story 30.27), à la Blizzard's generated icons. A member with
// no uploaded/external avatar gets a stable, colourful default deterministically picked from their name -
// so the same player always shows the same default everywhere, instead of a single flat placeholder.
const DEFAULT_VARIANTS = [
  "from-rose-500 to-orange-400",
  "from-amber-500 to-yellow-400",
  "from-emerald-500 to-teal-400",
  "from-cyan-500 to-sky-400",
  "from-indigo-500 to-violet-400",
  "from-fuchsia-500 to-pink-400",
  "from-purple-500 to-indigo-400",
  "from-lime-500 to-emerald-400",
] as const;

function defaultVariant(name: string): string {
  let hash = 0;
  for (let i = 0; i < name.length; i += 1) {
    hash = (hash * 31 + name.charCodeAt(i)) % 1_000_000_007;
  }
  return DEFAULT_VARIANTS[hash % DEFAULT_VARIANTS.length];
}
