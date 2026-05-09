"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useState } from "react";
import { TWITCH_CONSENT_KEY, setTwitchConsent } from "@/lib/consent";

type BannerMode = "summary" | "customize";

export function CookieConsentBanner() {
  const pathname = usePathname();
  const [show, setShow] = useState(false);
  const [mode, setMode] = useState<BannerMode>("summary");
  const [allowTwitch, setAllowTwitch] = useState(false);

  useEffect(() => {
    if (pathname === "/confidentialite") {
      const hideTimer = setTimeout(() => {
        setShow(false);
      }, 0);

      return () => clearTimeout(hideTimer);
    }

    const hydrateTimer = setTimeout(() => {
      if (localStorage.getItem(TWITCH_CONSENT_KEY) === null) {
        setShow(true);
      }
    }, 0);

    return () => clearTimeout(hydrateTimer);
  }, [pathname]);

  function accept() {
    setTwitchConsent(true);
    setShow(false);
  }

  function reject() {
    setTwitchConsent(false);
    setShow(false);
  }

  function saveConfiguration() {
    setTwitchConsent(allowTwitch);
    setShow(false);
  }

  if (!show) return null;

  return (
    <div
      aria-label="Gestion des cookies et contenus tiers"
      className="fixed bottom-0 left-0 right-0 z-50 border-t border-border bg-surface/95 backdrop-blur"
      role="dialog"
    >
      <div className="mx-auto grid max-w-7xl gap-4 px-6 py-5 md:px-12 lg:px-20">
        {mode === "summary" ? (
          <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-sm leading-6 text-muted-foreground">
              Ce site peut integrer un lecteur Twitch (contenu tiers) susceptible de transmettre
              des donnees a{" "}
              <strong className="font-medium text-foreground">Twitch Interactive, Inc.</strong>{" "}
              Aucun cookie publicitaire ni traqueur tiers n&apos;est depose sans votre accord. Les
              cookies de session strictement necessaires restent separes de ce choix.{" "}
              <Link className="underline hover:text-foreground" href="/confidentialite">
                En savoir plus
              </Link>
            </p>
            <div className="flex shrink-0 flex-wrap gap-3">
              <button
                className="inline-flex min-h-10 items-center justify-center rounded border border-border bg-background px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
                onClick={reject}
                type="button"
              >
                Refuser
              </button>
              <button
                className="inline-flex min-h-10 items-center justify-center rounded border border-border bg-background px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
                onClick={() => setMode("customize")}
                type="button"
              >
                Configurer
              </button>
              <button
                className="inline-flex min-h-10 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
                onClick={accept}
                type="button"
              >
                Accepter
              </button>
            </div>
          </div>
        ) : (
          <div className="grid gap-4">
            <div className="grid gap-2">
              <p className="font-heading text-base font-semibold text-foreground">
                Preferences de consentement
              </p>
              <p className="text-sm leading-6 text-muted-foreground">
                Les cookies de session necessaires au fonctionnement du compte et de la securite ne
                dependent pas de ce choix. Seul le lecteur Twitch integre est optionnel.
              </p>
            </div>
            <label className="flex items-start gap-3 rounded border border-border bg-background p-4">
              <input
                checked={allowTwitch}
                className="mt-1 size-4 accent-accent"
                onChange={(event) => setAllowTwitch(event.target.checked)}
                type="checkbox"
              />
              <span className="text-sm leading-6 text-muted-foreground">
                <strong className="font-medium text-foreground">Lecteur Twitch integre</strong>
                {" : "}autoriser le chargement du lecteur Twitch sur le site lorsqu&apos;un live est
                disponible.
              </span>
            </label>
            <div className="flex flex-wrap gap-3">
              <button
                className="inline-flex min-h-10 items-center justify-center rounded border border-border bg-background px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
                onClick={() => setMode("summary")}
                type="button"
              >
                Retour
              </button>
              <button
                className="inline-flex min-h-10 items-center justify-center rounded border border-border bg-background px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
                onClick={reject}
                type="button"
              >
                Tout refuser
              </button>
              <button
                className="inline-flex min-h-10 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
                onClick={saveConfiguration}
                type="button"
              >
                Enregistrer mes choix
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
