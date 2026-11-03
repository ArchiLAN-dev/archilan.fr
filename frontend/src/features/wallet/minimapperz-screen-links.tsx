"use client";

import { useState } from "react";
import { Check, Copy, ExternalLink, Tv } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { CHECKER_STYLE } from "@/features/overlay/overlay-links-panel";
import { env } from "@/lib/env";
import { minimapperzScreenUrl, type MinimapperzScreenMode } from "./minimapperz-screen";

const SCREENS: readonly { mode: MinimapperzScreenMode; label: string; hint: string }[] = [
  { mode: "overlay", label: "Overlay OBS", hint: "Source « Navigateur » 1920 × 1080, fond transparent, le top 5 en haut à droite (`lines`, `pos`, `scale` pour ajuster)." },
  { mode: "ecran", label: "Écran de la LAN", hint: "Plein écran : le top 10 et les dernières parties, à ouvrir sur l'écran de la salle." },
];

/**
 * Story 49.4: the links of the Minimapperz screen, ready to copy, with a preview of the overlay on a checkerboard so its
 * transparency shows. The screens re-read the board every 20 seconds.
 */
export function MinimapperzScreenLinks({ eventId }: { eventId: string }) {
  const [copied, setCopied] = useState<MinimapperzScreenMode | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function copy(url: string, mode: MinimapperzScreenMode): Promise<void> {
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
    <section aria-labelledby="ecran-minimapperz-heading" className="grid gap-4 rounded-xl border border-border p-4">
      <h2 className="flex items-center gap-2 font-heading text-base font-semibold text-foreground" id="ecran-minimapperz-heading">
        <Tv aria-hidden className="size-4 text-accent-text" />
        Écran Minimapperz
      </h2>
      <p className="text-sm text-muted-foreground">
        Le classement de la LAN, relu toutes les 20 secondes. Les joueurs reliés par leur code apparaissent sous leur pseudo du site.
      </p>
      {error !== null ? (
        <p className="text-sm text-danger" role="alert">
          {error}
        </p>
      ) : null}
      <ul className="grid gap-4">
        {SCREENS.map((screen) => {
          const url = minimapperzScreenUrl(env.appUrl, { eventId, mode: screen.mode });
          return (
            <li className="grid gap-3 lg:grid-cols-[1fr_auto] lg:items-start" key={screen.mode}>
              <div className="grid min-w-0 gap-2">
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
              </div>
              {screen.mode === "overlay" ? (
                <div className="relative max-w-full shrink-0 overflow-hidden rounded border border-border" style={CHECKER_STYLE}>
                  <iframe
                    className="absolute left-0 top-0 origin-top-left"
                    src={url}
                    style={{ width: 1280, height: 720, transform: "scale(0.25)", border: 0 }}
                    title="Aperçu de l'overlay Minimapperz"
                  />
                </div>
              ) : null}
            </li>
          );
        })}
      </ul>
    </section>
  );
}
