"use client";

import { useEffect, useState } from "react";
import { Loader2 } from "lucide-react";

import { getArchipelagoGuide, saveArchipelagoGuide } from "@/features/games/archipelago-guide-api";
import { InstallStepsEditor, type InstallStep } from "@/features/games/install-steps-editor";

/**
 * Admin editor for the generic "Installer Archipelago" guide steps (story 31.3), reusing the
 * shared install-steps editor.
 */
export function ArchipelagoGuideSettings() {
  const [steps, setSteps] = useState<InstallStep[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string } | null>(null);

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      const loaded = await getArchipelagoGuide();
      if (cancelled) return;
      setSteps(loaded);
      setLoading(false);
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  async function save() {
    setSaving(true);
    setMessage(null);
    const ok = await saveArchipelagoGuide(steps);
    setMessage(
      ok
        ? { tone: "ok", text: "Guide enregistré." }
        : { tone: "error", text: "Échec : vérifie les types d'étape et les liens (http(s))." },
    );
    setSaving(false);
  }

  return (
    <section className="rounded-lg border border-border bg-surface p-6">
      <h2 className="font-heading text-xl font-semibold text-foreground">Guide « Installer Archipelago »</h2>
      <p className="mt-1 text-sm text-muted-foreground">
        Étapes génériques affichées sur <span className="font-mono">/aide/archipelago</span>.
      </p>

      {loading ? (
        <p className="mt-4 flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden="true" className="size-4 animate-spin" /> Chargement…
        </p>
      ) : (
        <div className="mt-5 grid gap-4">
          <InstallStepsEditor onChange={setSteps} steps={steps} />
          <div className="flex flex-wrap items-center gap-3">
            <button
              className="inline-flex min-h-10 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
              disabled={saving}
              onClick={save}
              type="button"
            >
              {saving ? "Enregistrement…" : "Enregistrer le guide"}
            </button>
            {message ? (
              <span className={`text-sm ${message.tone === "ok" ? "text-success" : "text-danger"}`}>{message.text}</span>
            ) : null}
          </div>
        </div>
      )}
    </section>
  );
}
