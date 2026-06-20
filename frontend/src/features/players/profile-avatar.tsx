"use client";

import { useState } from "react";
import { AvatarFrame } from "@/features/community/avatar-frame";

const SIZE = "size-24 shrink-0 sm:size-28";

/**
 * Profile avatar with a deterministic initials fallback and an optional decorative frame. The fallback
 * covers both a missing URL and a *load error* of a cached URL (a snapshotted Discord/Steam URL can later
 * 404) - never a broken image (epic 30, review #4).
 */
export function ProfileAvatar({
  avatarUrl,
  name,
  frame = null,
}: {
  avatarUrl: string | null;
  name: string;
  frame?: string | null;
}) {
  const [failed, setFailed] = useState(false);

  const content =
    avatarUrl !== null && !failed ? (
      // eslint-disable-next-line @next/next/no-img-element -- external Discord/Steam CDN URL, not a local asset
      <img
        alt={name}
        className="h-full w-full bg-surface object-cover"
        onError={() => setFailed(true)}
        src={avatarUrl}
      />
    ) : (
      <div
        aria-hidden
        className={`flex h-full w-full items-center justify-center bg-gradient-to-br ${defaultVariant(name)} font-heading text-3xl font-bold text-white`}
      >
        {initials(name)}
      </div>
    );

  return (
    <AvatarFrame className={SIZE} frameKey={frame}>
      {content}
    </AvatarFrame>
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
