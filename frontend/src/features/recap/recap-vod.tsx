"use client";

import { Play } from "lucide-react";
import { useState } from "react";

/**
 * Consent-gated Twitch VOD embed (story 7.5 pattern): nothing from Twitch loads
 * on page view - the iframe is mounted only after an explicit click. Falls back
 * to an outbound link when the URL is not a recognisable Twitch VOD.
 */
export function RecapVod({ vodUrl }: { vodUrl: string }) {
  const [loaded, setLoaded] = useState(false);
  const [parent] = useState(() => (typeof window === "undefined" ? "" : window.location.hostname));

  const videoId = parseTwitchVideoId(vodUrl);

  if (videoId === null) {
    return (
      <a
        className="inline-flex min-h-11 items-center gap-2 rounded border border-border px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
        href={vodUrl}
        rel="noreferrer"
        target="_blank"
      >
        <Play aria-hidden="true" className="size-4" />
        Regarder la VOD
      </a>
    );
  }

  if (!loaded) {
    return (
      <button
        className="flex aspect-video w-full items-center justify-center gap-2 rounded-lg border border-border bg-surface text-sm font-semibold text-foreground transition-colors hover:border-accent"
        onClick={() => setLoaded(true)}
        type="button"
      >
        <Play aria-hidden="true" className="size-5 text-accent-text" />
        Charger la VOD Twitch
      </button>
    );
  }

  if (parent === "") return null;

  const src = `https://player.twitch.tv/?video=${encodeURIComponent(videoId)}&parent=${encodeURIComponent(parent)}&autoplay=false`;

  return (
    <div className="aspect-video w-full overflow-hidden rounded-lg border border-border">
      <iframe allowFullScreen className="h-full w-full" loading="lazy" src={src} title="VOD Twitch de la partie" />
    </div>
  );
}

function parseTwitchVideoId(vodUrl: string): string | null {
  try {
    const url = new URL(vodUrl);
    if (!url.hostname.endsWith("twitch.tv")) return null;
    const match = url.pathname.match(/\/videos\/(\d+)/);
    return match ? match[1] : null;
  } catch {
    return null;
  }
}
