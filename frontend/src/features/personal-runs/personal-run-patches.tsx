"use client";

import { useQuery } from "@tanstack/react-query";
import { Download, Package } from "lucide-react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { parsePatchFiles, type PatchFile } from "@/lib/patch-files";

async function fetchPatches(runId: string): Promise<PatchFile[]> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}/patches`);
    if (!res.ok) return [];
    return parsePatchFiles(await res.json());
  } catch {
    return [];
  }
}

/**
 * Download the patch(es) generated for the current participant's own slot. Renders
 * nothing when there are no files (run not generated, or the user has no slot).
 */
export function PersonalRunPatchPanel({ runId, enabled }: { runId: string; enabled: boolean }) {
  const { data: files = [] } = useQuery({
    queryKey: ["personal-run-patches", runId],
    queryFn: () => fetchPatches(runId),
    enabled,
    staleTime: 30_000,
    // The patch is generated shortly AFTER the session appears (sessionId set), so a one-shot fetch on
    // enable often returns nothing and the panel would only show on reload. Poll until the patch shows
    // up, then stop (patches never change once generated).
    refetchInterval: (query) => ((query.state.data ?? []).length > 0 ? false : 5_000),
  });

  if (files.length === 0) return null;

  return (
    <div className="rounded-lg border border-border bg-surface p-4">
      <div className="mb-3 flex items-center gap-2">
        <Package aria-hidden className="size-4 text-accent-text" />
        <h3 className="text-sm font-semibold text-foreground">Fichiers générés</h3>
      </div>
      <p className="mb-3 text-sm text-muted-foreground">
        Le patch de ton slot pour cette partie - applique-le à ta ROM pour jouer. Clic droit sur un
        fichier pour copier son lien : il est téléchargeable sans compte, à envoyer tel quel.
      </p>
      <div className="flex flex-wrap gap-2">
        {files.map((file) => (
          <a
            className="inline-flex max-w-full items-center gap-1.5 rounded border border-border bg-background px-3 py-2 text-sm font-medium text-foreground transition-colors hover:border-accent"
            download={file.name}
            href={file.url ?? `${env.apiBaseUrl}/runs/${runId}/patches/${encodeURIComponent(file.name)}`}
            key={file.name}
            title={file.name}
          >
            <Download aria-hidden className="size-3.5 shrink-0 text-accent-text" />
            <span className="min-w-0 truncate">{file.name}</span>
          </a>
        ))}
      </div>
    </div>
  );
}
