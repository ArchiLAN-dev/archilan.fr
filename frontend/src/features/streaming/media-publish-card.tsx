"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Check, Copy, Eye, EyeOff, MonitorUp, RefreshCw } from "lucide-react";

import { HelpLink } from "@/components/help-link";
import { buttonVariants } from "@/components/ui/button";
import { fetchMediaPublish, mediaPublishKey, regenerateMediaKey, type MediaPublish } from "./media-publish-api";

type Access = Extract<MediaPublish, { available: true }>;

/** Read again this often, so "La régie te reçoit" follows the régie agent (it reports every 10 s). */
const REFRESH_MS = 15_000;

/**
 * Story 47.7: in the registrant hub, how to send one's screen to the régie over the LAN media server, and whether it
 * arrives. Nothing without access (no media server, not confirmed, broadcast refused, before the day before).
 */
export function MediaPublishCard({ registrationId, helpHref = null }: { registrationId: string; helpHref?: string | null }) {
  const { data } = useQuery({
    queryKey: mediaPublishKey(registrationId),
    queryFn: () => fetchMediaPublish(registrationId),
    staleTime: REFRESH_MS,
    // v0.66.1: only while the registrant has access (the "La régie te reçoit" line); otherwise one read is enough.
    refetchInterval: (query) => (query.state.data?.available ? REFRESH_MS : false),
    retry: false,
  });

  if (!data?.available) return null;
  return <MediaPublishPanel access={data} helpHref={helpHref} registrationId={registrationId} />;
}

type PanelProps = { access: Access; registrationId: string; helpHref?: string | null };

export function MediaPublishPanel({ access, registrationId, helpHref = null }: PanelProps) {
  const queryClient = useQueryClient();
  const [revealed, setRevealed] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [copied, setCopied] = useState<string | null>(null);
  const masked = "•".repeat(access.key.length);

  async function copy(text: string, field: string): Promise<void> {
    try {
      await navigator.clipboard.writeText(text);
      setError(null);
      setCopied(field);
      window.setTimeout(() => setCopied((current) => (current === field ? null : current)), 1_500);
    } catch {
      setError("Copie impossible : copie la valeur à la main.");
    }
  }

  async function regenerate(): Promise<void> {
    setPending(true);
    setError(null);
    const renewed = await regenerateMediaKey(registrationId);
    setPending(false);
    setConfirming(false);
    if (renewed === null) {
      setError("La clé n'a pas pu être régénérée.");
      return;
    }
    queryClient.setQueryData(mediaPublishKey(registrationId), renewed);
  }

  function copyButton(text: string, field: string, label: string) {
    return (
      <button aria-label={label} className={buttonVariants({ variant: "secondary" })} onClick={() => void copy(text, field)} type="button">
        {copied === field ? <Check aria-hidden className="size-4" /> : <Copy aria-hidden className="size-4" />}
        {copied === field ? "Copié" : "Copier"}
      </button>
    );
  }

  return (
    <section aria-labelledby="media-publish-heading" className="grid gap-3 rounded-xl border border-border bg-background p-4">
      <h2 className="flex items-center gap-2 text-sm font-semibold text-foreground" id="media-publish-heading">
        <MonitorUp aria-hidden className="size-4 text-accent-text" />
        Diffuser pour la régie
      </h2>
      <p aria-live="polite" className={access.live ? "text-sm font-semibold text-success" : "text-sm text-muted-foreground"}>
        {access.live ? "La régie te reçoit." : "En attente de ton flux."}
      </p>
      <p className="text-sm text-muted-foreground">
        Envoie ton écran à la régie par le réseau de la LAN, sans rien publier sur Internet. Dans OBS 30 ou plus récent :
        <span className="font-semibold text-foreground"> Paramètres &gt; Stream</span>, service <span className="font-semibold text-foreground">WHIP</span>.
      </p>

      <div className="grid gap-1">
        <span className="text-xs font-semibold text-foreground">Serveur</span>
        <div className="flex flex-wrap items-center gap-2">
          <code className="min-w-0 flex-1 truncate rounded-lg border border-border bg-surface px-3 py-2 text-xs text-foreground">{access.whipUrl}</code>
          {copyButton(access.whipUrl, "whip", "Copier le serveur")}
        </div>
      </div>

      <div className="grid gap-1">
        <span className="text-xs font-semibold text-foreground">Jeton Bearer (ta clé, personnelle)</span>
        <div className="flex flex-wrap items-center gap-2">
          <code className="min-w-0 flex-1 truncate rounded-lg border border-border bg-surface px-3 py-2 text-xs text-foreground">
            {revealed ? access.key : masked}
          </code>
          <button
            aria-label={revealed ? "Masquer la clé" : "Afficher la clé"}
            className={buttonVariants({ variant: "ghost" })}
            onClick={() => setRevealed((value) => !value)}
            type="button"
          >
            {revealed ? <EyeOff aria-hidden className="size-4" /> : <Eye aria-hidden className="size-4" />}
            {revealed ? "Masquer" : "Afficher"}
          </button>
          {copyButton(access.key, "key", "Copier la clé")}
        </div>
      </div>

      <details className="grid gap-2 text-sm">
        <summary className="cursor-pointer text-sm font-semibold text-foreground">En repli : SRT</summary>
        <p className="mt-2 text-xs text-muted-foreground">
          Service « Personnalisé », serveur ci-dessous (il contient ta clé). Mets les <span className="font-semibold text-foreground">B-frames à 0</span>{" "}
          dans les réglages de l&apos;encodeur, sinon la régie ne peut pas lire ton flux.
        </p>
        <div className="flex flex-wrap items-center gap-2">
          <code className="min-w-0 flex-1 truncate rounded-lg border border-border bg-surface px-3 py-2 text-xs text-foreground">
            {revealed ? access.srtUrl : access.srtUrl.replace(access.key, masked)}
          </code>
          {copyButton(access.srtUrl, "srt", "Copier l'adresse SRT")}
        </div>
      </details>

      <p className="text-xs text-muted-foreground">
        Réglages conseillés : 720p60 ou 1080p60, encodeur de la carte graphique (NVENC ou AMF), 6000 kbit/s, une image clé
        toutes les 1 à 2 secondes.
      </p>

      <div className="flex flex-wrap items-center gap-2">
        {confirming ? (
          <>
            <button className={buttonVariants({ variant: "secondary" })} disabled={pending} onClick={() => void regenerate()} type="button">
              <RefreshCw aria-hidden className="size-4" />
              Confirmer : l&apos;ancienne clé cessera de marcher
            </button>
            <button className={buttonVariants({ variant: "ghost" })} disabled={pending} onClick={() => setConfirming(false)} type="button">
              Annuler
            </button>
          </>
        ) : (
          <button className={buttonVariants({ variant: "ghost" })} onClick={() => setConfirming(true)} type="button">
            <RefreshCw aria-hidden className="size-4" />
            Régénérer ma clé
          </button>
        )}
      </div>
      {error !== null ? (
        <p className="text-sm text-danger" role="alert">
          {error}
        </p>
      ) : null}
      <HelpLink href={helpHref} />
    </section>
  );
}
