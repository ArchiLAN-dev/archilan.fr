"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Music, QrCode } from "lucide-react";

import { qrPath } from "@/features/community/friend-link";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchBlindTestEntryUrl } from "./blind-test-api";

const QUIET_ZONE = 4;

/**
 * Stories 41.40, 41.45: a registrant's way into the event's blind test, shown only while they may play. The button
 * opens the app here; the QR code opens it on a phone. Either way the app starts the login, and the site tells it who
 * plays through a one-time code: nothing in this address identifies the member.
 */
export function BlindTestJoinButton({ eventId }: { eventId: string }) {
  const [showQr, setShowQr] = useState(false);
  const { data: url } = useQuery({
    queryKey: ["blind-test-entry", eventId],
    queryFn: () => fetchBlindTestEntryUrl(eventId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (!url) return null;

  return (
    <div className="grid justify-items-start gap-3">
      <div className="flex flex-wrap items-center gap-2">
        <a
          className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-accent/50 bg-accent/10 px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
          href={url}
          rel="noopener noreferrer"
          target="_blank"
        >
          <Music aria-hidden className="size-4 text-accent-text" />
          Jouer au blind test
        </a>
        <button
          aria-expanded={showQr}
          className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-border px-3 text-sm text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
          onClick={() => setShowQr((shown) => !shown)}
          type="button"
        >
          <QrCode aria-hidden className="size-4" />
          {showQr ? "Masquer le QR code" : "Sur mon téléphone"}
        </button>
      </div>
      {showQr ? <BlindTestQr url={url} /> : null}
    </div>
  );
}

/** Black on white whatever the theme: a phone camera reads contrast, not tokens. */
export function BlindTestQr({ url }: { url: string }) {
  const { size, d } = qrPath(url);
  const box = size + QUIET_ZONE * 2;

  return (
    <figure className="grid gap-2">
      <svg aria-label="QR code du blind test" className="size-44 rounded-lg" role="img" shapeRendering="crispEdges" viewBox={`0 0 ${box} ${box}`}>
        <rect fill="#ffffff" height={box} width={box} />
        <path d={d} fill="#000000" transform={`translate(${QUIET_ZONE} ${QUIET_ZONE})`} />
      </svg>
      <figcaption className="max-w-56 text-xs text-muted-foreground">
        Scanne-le avec ton téléphone : il te demandera de te connecter à archilan.fr si ce n&apos;est pas déjà fait.
      </figcaption>
    </figure>
  );
}
