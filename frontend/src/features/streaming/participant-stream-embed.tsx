"use client";

import { useState } from "react";

type Props = {
  channel: string;
};

/**
 * A single Twitch player iframe parametrized by channel. Unlike the global ConsentGatedTwitchEmbed
 * (hardwired to the ArchiLAN channel), this renders any participant's channel. Mounted lazily by the
 * widget only after an explicit card click, so no third-party Twitch content loads on page view.
 */
export function ParticipantStreamEmbed({ channel }: Props) {
  // The Twitch player requires the embedding page's hostname as `parent`. Resolve it once via a lazy
  // state initializer (client-only); this component only ever mounts after a client-side interaction.
  const [parent] = useState(() => (typeof window === "undefined" ? "" : window.location.hostname));

  if (parent === "") {
    return null;
  }

  const src = `https://player.twitch.tv/?channel=${encodeURIComponent(channel)}&parent=${encodeURIComponent(parent)}`;

  return (
    <div className="aspect-video w-full overflow-hidden rounded-lg border border-border bg-surface">
      <iframe
        allowFullScreen
        className="size-full"
        loading="lazy"
        src={src}
        title={`Stream Twitch de ${channel}`}
      />
    </div>
  );
}
