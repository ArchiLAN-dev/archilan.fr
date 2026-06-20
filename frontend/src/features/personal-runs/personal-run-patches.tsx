"use client";

import { useQuery } from "@tanstack/react-query";
import { Download, Package } from "lucide-react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

async function fetchPatches(runId: string): Promise<string[]> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}/patches`);
    if (!res.ok) return [];
    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("data" in payload)) return [];
    const data: unknown = (payload as { data: unknown }).data;
    if (typeof data !== "object" || data === null || !("files" in data)) return [];
    const files: unknown = (data as { files: unknown }).files;
    return Array.isArray(files) ? files.filter((f): f is string => typeof f === "string") : [];
  } catch {
    return [];
  }
}

async function downloadPatch(runId: string, filename: string): Promise<void> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}/patches/${encodeURIComponent(filename)}`);
    if (!res.ok) return;
    const blob = await res.blob();
    const objectUrl = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = objectUrl;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(objectUrl);
  } catch {
    // non-critical - the user will notice the file didn't arrive
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
        Le patch de ton slot pour cette partie - applique-le à ta ROM pour jouer.
      </p>
      <div className="flex flex-wrap gap-2">
        {files.map((filename) => (
          <button
            className="inline-flex max-w-full items-center gap-1.5 rounded border border-border bg-background px-3 py-2 text-sm font-medium text-foreground transition-colors hover:border-accent"
            key={filename}
            onClick={() => { void downloadPatch(runId, filename); }}
            title={filename}
            type="button"
          >
            <Download aria-hidden className="size-3.5 shrink-0 text-accent-text" />
            <span className="min-w-0 truncate">{filename}</span>
          </button>
        ))}
      </div>
    </div>
  );
}
