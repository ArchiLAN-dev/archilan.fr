"use client";

import { useState } from "react";
import { Download, ScrollText } from "lucide-react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

async function downloadSpoiler(runId: string): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}/spoiler`);
    if (!res.ok) return false;

    const blob = await res.blob();
    const disposition = res.headers.get("Content-Disposition") ?? "";
    const match = /filename="?([^"]+)"?/.exec(disposition);
    const filename = match?.[1] ?? `spoiler-${runId}.txt`;

    const objectUrl = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = objectUrl;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(objectUrl);
    return true;
  } catch {
    return false;
  }
}

/**
 * Owner/admin-only download of the run's full spoiler log, served from durable storage
 * (works whatever the run's state). Renders nothing unless `enabled`. The shared multidata
 * and other players' patches are never exposed by the underlying endpoint.
 */
export function PersonalRunSpoilerPanel({ runId, enabled }: { runId: string; enabled: boolean }) {
  const [status, setStatus] = useState<"idle" | "loading" | "unavailable">("idle");

  if (!enabled) return null;

  return (
    <div className="rounded-lg border border-border bg-surface p-4">
      <div className="mb-3 flex items-center gap-2">
        <ScrollText aria-hidden className="size-4 text-accent-text" />
        <h3 className="text-sm font-semibold text-foreground">Spoiler</h3>
      </div>
      <p className="mb-3 text-sm text-muted-foreground">
        Le spoiler complet de la partie (la solution). Réservé à l&apos;organisateur et aux admins.
      </p>
      <button
        className="inline-flex items-center gap-1.5 rounded border border-border bg-background px-3 py-2 text-sm font-medium text-foreground transition-colors hover:border-accent disabled:opacity-60"
        disabled={status === "loading"}
        onClick={() => {
          setStatus("loading");
          void downloadSpoiler(runId).then((ok) => { setStatus(ok ? "idle" : "unavailable"); });
        }}
        type="button"
      >
        <Download aria-hidden className="size-3.5 shrink-0 text-accent-text" />
        <span>{status === "loading" ? "Téléchargement…" : "Télécharger le spoiler"}</span>
      </button>
      {status === "unavailable" && (
        <p className="mt-2 text-sm text-muted-foreground">Spoiler non disponible pour cette partie.</p>
      )}
    </div>
  );
}
