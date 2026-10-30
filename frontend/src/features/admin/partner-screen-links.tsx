"use client";

import { useState } from "react";
import { Check, Copy, ExternalLink, Tv } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { partnerScreenUrl, type PartnerScreenMode } from "@/features/content/partner-screen";
import { CHECKER_STYLE } from "@/features/overlay/overlay-links-panel";
import { env } from "@/lib/env";

const SCREENS: readonly { mode: PartnerScreenMode; label: string; hint: string }[] = [
  { mode: "overlay", label: "Overlay OBS", hint: "Source « Navigateur » 1920 × 1080, fond transparent, un partenaire à la fois en bas à droite." },
  { mode: "ecran", label: "Écran de la LAN", hint: "Plein écran, à ouvrir sur l'écran de la salle." },
];

/**
 * Story 45.4: the links of the partner screen, ready to copy, with a preview of the overlay on a checkerboard so its
 * transparency shows. With an event, the screen keeps to that event's partners.
 */
export function PartnerScreenLinks({ eventId }: { eventId: string | null }) {
  const [copied, setCopied] = useState<PartnerScreenMode | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function copy(url: string, mode: PartnerScreenMode): Promise<void> {
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
    <section aria-labelledby="ecran-partenaires-heading" className="grid gap-4 rounded-xl border border-border bg-surface p-4">
      <h2 className="flex items-center gap-2 font-heading text-lg font-semibold text-foreground" id="ecran-partenaires-heading">
        <Tv aria-hidden className="size-4 text-accent-text" />
        Écran partenaires
      </h2>
      <p className="text-sm text-muted-foreground">
        {eventId !== null ? "Les partenaires de cet événement, " : "Tous les partenaires affichés, "}
        relus toutes les 5 minutes : un changement en admin arrive sans toucher à OBS.
      </p>
      {error !== null ? (
        <p className="text-sm text-danger" role="alert">
          {error}
        </p>
      ) : null}
      <ul className="grid gap-4">
        {SCREENS.map((screen) => {
          const url = partnerScreenUrl(env.appUrl, { eventId, mode: screen.mode });
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
                    title="Aperçu de l'overlay partenaires"
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
