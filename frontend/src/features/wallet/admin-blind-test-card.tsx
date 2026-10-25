"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Copy, ExternalLink, Loader2, Music } from "lucide-react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchBlindTestSettings, saveBlindTestSettings, type BlindTestSettings } from "./blind-test-api";

/** A form value read as a whole number, or null when it is not one. */
export function wholeNumber(value: string): number | null {
  return /^\d+$/.test(value.trim()) ? Number(value.trim()) : null;
}

/**
 * Story 41.40: the event's blind test, admin side. Off by default; each source found is worth a fixed amount of event
 * pelles, up to a cap per member over the event. Story 41.46: the event's id, which the app's console asks for, and the
 * way to that console.
 */
export function AdminBlindTestCard({ eventId }: { eventId: string }) {
  const { data } = useQuery({
    queryKey: ["admin-blind-test", eventId],
    queryFn: () => fetchBlindTestSettings(eventId),
    staleTime: DEFAULT_STALE_TIME,
  });

  if (!data) return null;

  return <AdminBlindTestForm eventId={eventId} initial={data} />;
}

function AdminBlindTestForm({ eventId, initial }: { eventId: string; initial: BlindTestSettings }) {
  const queryClient = useQueryClient();
  const [enabled, setEnabled] = useState(initial.enabled);
  const [perSource, setPerSource] = useState(String(initial.pellesPerSource));
  const [cap, setCap] = useState(String(initial.capPerPlayer));
  const [pending, setPending] = useState(false);
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string } | null>(null);
  const [copied, setCopied] = useState(false);

  const pellesPerSource = wholeNumber(perSource);
  const capPerPlayer = wholeNumber(cap);
  const canSubmit = !pending && pellesPerSource !== null && capPerPlayer !== null;

  async function submit() {
    if (pellesPerSource === null || capPerPlayer === null) return;
    setPending(true);
    const result = await saveBlindTestSettings(eventId, { enabled, pellesPerSource, capPerPlayer });
    setPending(false);
    if (result.kind === "ok") {
      queryClient.setQueryData(["admin-blind-test", eventId], result.settings);
      setMessage({ tone: "ok", text: "Réglages enregistrés." });
    } else {
      setMessage({ tone: "error", text: result.message });
    }
  }

  const fieldClass = "min-h-9 rounded-lg border border-border bg-background px-3 text-sm text-foreground";

  return (
    <form
      className="grid gap-3 rounded-xl border border-border p-4 sm:grid-cols-2"
      onSubmit={(event) => {
        event.preventDefault();
        void submit();
      }}
    >
      <h2 className="flex items-center gap-2 font-heading text-lg font-semibold text-foreground sm:col-span-2">
        <Music aria-hidden className="size-5 text-accent-text" />
        Blind test
      </h2>
      <p className="text-sm text-muted-foreground sm:col-span-2">
        L&apos;app du blind test crédite des pelles d&apos;événement pour chaque source trouvée par un inscrit, pendant
        l&apos;événement. La couper arrête aussitôt les crédits et les liens.
      </p>
      <div className="flex flex-wrap items-center gap-2 text-sm sm:col-span-2">
        <span className="text-muted-foreground">Identifiant pour l&apos;app :</span>
        <code className="rounded bg-surface px-1.5 py-0.5 text-foreground">{eventId}</code>
        <button
          className="inline-flex min-h-8 items-center gap-1 rounded-lg border border-border px-2 text-xs font-semibold text-foreground transition-colors hover:border-accent"
          onClick={() => {
            void navigator.clipboard.writeText(eventId).then(
              () => setCopied(true),
              () => setCopied(false),
            );
          }}
          type="button"
        >
          <Copy aria-hidden className="size-3.5" />
          {copied ? "Copié" : "Copier"}
        </button>
        {initial.consoleUrl !== null ? (
          <a
            className="inline-flex min-h-8 items-center gap-1 rounded-lg border border-border px-2 text-xs font-semibold text-accent-text transition-colors hover:border-accent"
            href={initial.consoleUrl}
            rel="noopener noreferrer"
            target="_blank"
          >
            <ExternalLink aria-hidden className="size-3.5" />
            Ouvrir la console du blind test
          </a>
        ) : null}
      </div>
      <label className="flex items-center gap-2 text-sm font-medium text-foreground sm:col-span-2">
        <input checked={enabled} onChange={(e) => setEnabled(e.target.checked)} type="checkbox" />
        Blind test ouvert
      </label>
      <label className="grid gap-1 text-sm">
        <span className="font-medium text-foreground">Pelles par source trouvée (1 à 100)</span>
        <input className={fieldClass} inputMode="numeric" max={100} min={1} onChange={(e) => setPerSource(e.target.value)} type="number" value={perSource} />
      </label>
      <label className="grid gap-1 text-sm">
        <span className="font-medium text-foreground">Plafond par membre (1 à 10 000)</span>
        <input className={fieldClass} inputMode="numeric" max={10000} min={1} onChange={(e) => setCap(e.target.value)} type="number" value={cap} />
      </label>
      <div className="sm:col-span-2">
        <button
          className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-40"
          disabled={!canSubmit}
          type="submit"
        >
          {pending ? <Loader2 aria-hidden className="size-4 animate-spin" /> : null}
          Enregistrer
        </button>
      </div>
      {message !== null ? (
        <p className={`text-sm sm:col-span-2 ${message.tone === "ok" ? "text-success" : "text-danger"}`}>{message.text}</p>
      ) : null}
    </form>
  );
}
