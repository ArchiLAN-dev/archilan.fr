"use client";

import { useState } from "react";

const RING = "size-24 shrink-0 rounded-2xl border-4 border-surface ring-1 ring-border sm:size-28";

/**
 * Profile avatar with a deterministic initials fallback. The fallback covers both a missing URL and a
 * *load error* of a cached URL (a snapshotted Discord/Steam URL can later 404) - never a broken image
 * (epic 30, review #4).
 */
export function ProfileAvatar({ avatarUrl, name }: { avatarUrl: string | null; name: string }) {
  const [failed, setFailed] = useState(false);

  if (avatarUrl !== null && !failed) {
    return (
      // eslint-disable-next-line @next/next/no-img-element -- external Discord/Steam CDN URL, not a local asset
      <img
        alt={name}
        className={`${RING} bg-surface object-cover`}
        onError={() => setFailed(true)}
        src={avatarUrl}
      />
    );
  }

  return (
    <div
      aria-hidden
      className={`${RING} flex items-center justify-center bg-gradient-to-br from-accent/40 to-accent/10 font-heading text-3xl font-bold text-accent-text`}
    >
      {initials(name)}
    </div>
  );
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return "?";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}
