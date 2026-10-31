"use client";

import { useState } from "react";
import { Cast, Check, Copy, ExternalLink } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { broadcastScreenUrl, type BroadcastScreenMode } from "@/features/streaming/broadcast-screen";
import { env } from "@/lib/env";

const SCREENS: readonly { mode: BroadcastScreenMode; label: string; hint: string }[] = [
  { mode: "mosaique", label: "Mosaïque", hint: "Tous les lives côte à côte (4 au plus, ajouter &max=9 pour monter à 9), les groupes tournent au-delà." },
  { mode: "rotation", label: "Rotation", hint: "Un live en grand, les suivants en vignettes, le grand change toutes les 30 s (&duree= en secondes)." },
];

/**
 * Story 47.2: the régie screen's links, ready to paste in OBS. No preview here: it would start Twitch players in the
 * admin page.
 */
export function BroadcastScreenLinks({ eventId }: { eventId: string }) {
  const [copied, setCopied] = useState<BroadcastScreenMode | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function copy(url: string, mode: BroadcastScreenMode): Promise<void> {
    try {
      await navigator.clipboard.writeText(url);
      setError(null);
      setCopied(mode);
      window.setTimeout(() => setCopied((current) => (current === mode ? null : current)), 1_500);
    } catch {
      setError("Copie impossible : copie le lien à la main.");
    }
  }

  return (
    <section aria-labelledby="ecran-regie-heading" className="grid gap-4 rounded-xl border border-border bg-surface p-4">
      <h2 className="flex items-center gap-2 font-heading text-lg font-semibold text-foreground" id="ecran-regie-heading">
        <Cast aria-hidden className="size-4 text-accent-text" />
        Écran régie
      </h2>
      <p className="text-sm text-muted-foreground">
        Les inscrits en live, sauf ceux qui ont refusé la diffusion. Source « Navigateur » 1920 × 1080 dans OBS, liste
        relue chaque minute ; les lecteurs sont muets, le son se règle dans OBS.
      </p>
      {error !== null ? (
        <p className="text-sm text-danger" role="alert">
          {error}
        </p>
      ) : null}
      <ul className="grid gap-4 md:grid-cols-2">
        {SCREENS.map((screen) => {
          const url = broadcastScreenUrl(env.appUrl, eventId, screen.mode);
          return (
            <li className="grid min-w-0 gap-2" key={screen.mode}>
              <p className="text-sm font-semibold text-foreground">{screen.label}</p>
              <p className="text-xs text-muted-foreground">{screen.hint}</p>
              <code className="block truncate rounded-lg border border-border bg-background px-3 py-2 text-xs text-foreground">{url}</code>
              <div className="flex flex-wrap gap-2">
                <button className={buttonVariants({ variant: "secondary" })} onClick={() => void copy(url, screen.mode)} type="button">
                  {copied === screen.mode ? <Check aria-hidden className="size-4" /> : <Copy aria-hidden className="size-4" />}
                  {copied === screen.mode ? "Copié" : "Copier le lien"}
                </button>
                <a className={buttonVariants({ variant: "ghost" })} href={url} rel="noreferrer" target="_blank">
                  <ExternalLink aria-hidden className="size-4" />
                  Ouvrir
                </a>
              </div>
            </li>
          );
        })}
      </ul>
    </section>
  );
}
