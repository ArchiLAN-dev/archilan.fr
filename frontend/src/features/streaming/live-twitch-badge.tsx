"use client";

import { externalLinks } from "@/lib/external-links";
import { useTwitchStatus } from "@/hooks/use-twitch-status";
import { LiveMark } from "./live-mark";

type LiveTwitchBadgeProps = {
  onNavigate?: () => void;
};

export function LiveTwitchBadge({ onNavigate }: LiveTwitchBadgeProps) {
  const { live } = useTwitchStatus();

  if (!live) return null;

  return (
    <a
      aria-label="ArchiLAN est en direct sur Twitch - rejoindre le stream (nouvel onglet)"
      aria-live="polite"
      className="inline-flex min-h-11 items-center gap-2.5 border-b-2 border-transparent px-1 transition-opacity duration-200 hover:opacity-75"
      href={externalLinks.twitch}
      onClick={onNavigate}
      rel="noopener noreferrer"
      target="_blank"
    >
      <LiveMark />
    </a>
  );
}
