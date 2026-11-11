"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Compass, Copy, ExternalLink } from "lucide-react";

import { useAuth } from "@/features/auth/auth-context";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { claimMinimapperzCode } from "./minimapperz-api";
import { HelpLink } from "@/components/help-link";

/**
 * Story 49.2: a registrant's Minimapperz code, shown on the event's page while the link is on. The first visit hands
 * the code out; it stays the same afterwards. Typed when joining the LAN on Minimapperz, it ties the games played
 * there to the member's pelles.
 */
export function MinimapperzCodeCard({ eventId, helpHref }: { eventId: string; helpHref?: string | null }) {
  const { user } = useAuth();
  const [copied, setCopied] = useState(false);
  const { data } = useQuery({
    // A visitor has no code: no call, no 401 to refresh.
    enabled: user !== null,
    queryKey: ["minimapperz-code", eventId],
    queryFn: () => claimMinimapperzCode(eventId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (!data) return null;

  return (
    <div className="grid max-w-md gap-2 rounded-xl border border-accent/40 bg-accent/5 p-4">
      <p className="flex items-center gap-2 text-sm font-semibold text-foreground">
        <Compass aria-hidden className="size-4 text-accent-text" />
        Ton code Minimapperz
      </p>
      <div className="flex flex-wrap items-center gap-2">
        <code className="rounded-lg bg-surface px-3 py-1.5 font-mono text-base font-semibold text-foreground">{data.code}</code>
        <button
          className="inline-flex min-h-9 items-center gap-1 rounded-lg border border-border px-2 text-xs font-semibold text-foreground transition-colors hover:border-accent"
          onClick={() => {
            void navigator.clipboard.writeText(data.code).then(
              () => setCopied(true),
              () => setCopied(false),
            );
          }}
          type="button"
        >
          <Copy aria-hidden className="size-3.5" />
          {copied ? "Copié" : "Copier"}
        </button>
        <a
          className="inline-flex min-h-9 items-center gap-1 rounded-lg border border-border px-2 text-xs font-semibold text-accent-text transition-colors hover:border-accent"
          href={data.lanUrl}
          rel="noopener noreferrer"
          target="_blank"
        >
          <ExternalLink aria-hidden className="size-3.5" />
          Rejoindre la LAN
        </a>
      </div>
      <p className="text-xs text-muted-foreground">
        Saisis ce code en rejoignant la LAN sur Minimapperz : tes parties te rapporteront des pelles d&apos;argent. Il est à toi seul, ne le partage pas.
      </p>
      <HelpLink href={helpHref} />
    </div>
  );
}
