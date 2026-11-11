"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Check, Copy, KeyRound } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { env } from "@/lib/env";

import { fetchAdminMediaAgent, mediaAgentEnvLines } from "./admin-events-api";

type Props = { eventId: string };

/** Story 47.9: the setup procedure of the LAN media server and the régie agent. */
export const LAN_MEDIA_PROCEDURE_URL = "https://github.com/ArchiLAN-dev/archilan.fr/tree/main/ops/lan-media";

/**
 * Story 47.6: the three lines of `ops/lan-media/.env` that let the régie agent report the streams of this event. The
 * secret is only fetched on demand.
 */
export function MediaAgentConfig({ eventId }: Props) {
  const [open, setOpen] = useState(false);

  if (!open) {
    return (
      <div>
        <button className={buttonVariants({ variant: "secondary" })} onClick={() => setOpen(true)} type="button">
          <KeyRound aria-hidden className="size-4" />
          Configuration de l&apos;agent
        </button>
      </div>
    );
  }

  return <MediaAgentLines eventId={eventId} />;
}

function MediaAgentLines({ eventId }: Props) {
  const [copied, setCopied] = useState(false);
  const [copyError, setCopyError] = useState(false);
  const { data, isLoading } = useQuery({
    queryKey: ["admin", "events", eventId, "media-agent"],
    queryFn: () => fetchAdminMediaAgent(eventId),
    staleTime: Infinity,
  });
  const lines = data ? mediaAgentEnvLines(env.apiBaseUrl, data) : null;

  async function copy(text: string): Promise<void> {
    try {
      await navigator.clipboard.writeText(text);
      setCopyError(false);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1_500);
    } catch {
      setCopyError(true);
    }
  }

  return (
    <div className="grid gap-2">
      <p className="text-xs text-muted-foreground">
        À coller dans <code>ops/lan-media/.env</code> sur la machine de régie, puis <code>docker compose up -d</code>. Le secret
        est propre à cet événement : ne le partage pas.
      </p>
      <p className="text-xs text-muted-foreground">
        Mise en place du serveur et de l&apos;agent :{" "}
        <a className="font-medium text-primary underline-offset-2 hover:underline" href={LAN_MEDIA_PROCEDURE_URL} rel="noreferrer" target="_blank">
          procédure ops/lan-media
        </a>
        .
      </p>
      {isLoading ? <p className="text-sm text-muted-foreground">Chargement…</p> : null}
      {!isLoading && lines === null ? (
        <p className="text-sm text-danger" role="alert">
          Configuration indisponible : enregistre d&apos;abord l&apos;événement, puis réessaie.
        </p>
      ) : null}
      {lines !== null ? (
        <>
          <pre className="overflow-x-auto rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground">{lines}</pre>
          <div>
            <button className={buttonVariants({ variant: "secondary" })} onClick={() => void copy(lines)} type="button">
              {copied ? <Check aria-hidden className="size-4" /> : <Copy aria-hidden className="size-4" />}
              {copied ? "Copié" : "Copier"}
            </button>
          </div>
          {copyError ? (
            <p className="text-sm text-danger" role="alert">
              Copie impossible : copie les lignes à la main.
            </p>
          ) : null}
        </>
      ) : null}
    </div>
  );
}
