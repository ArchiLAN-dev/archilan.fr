"use client";

import { externalLinks } from "@/lib/external-links";
import { useTwitchStatus } from "@/hooks/use-twitch-status";

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
      <span aria-hidden="true" className="relative flex size-3.5 shrink-0">
        <svg className="absolute inset-0 animate-ping" fill="none" viewBox="0 0 14 14">
          <circle cx="7" cy="7" r="6" stroke="#f87171" strokeWidth="1.5" />
        </svg>
        <svg className="relative" fill="none" viewBox="0 0 14 14">
          <circle cx="7" cy="7" fill="#991b1b" r="5" />
        </svg>
      </span>
      <span className="text-xs font-semibold uppercase tracking-widest" style={{ color: "#ef4444" }}>
        Live
      </span>
    </a>
  );
}
