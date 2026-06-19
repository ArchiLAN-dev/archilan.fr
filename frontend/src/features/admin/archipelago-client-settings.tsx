"use client";

import { useEffect, useState } from "react";
import { Loader2 } from "lucide-react";

import { getArchipelagoClient, saveArchipelagoClient } from "@/features/games/archipelago-client-api";

/**
 * Admin editor for the global Archipelago client version + download URL (story 31.8),
 * shown to players as the client version to install for version parity.
 */
export function ArchipelagoClientSettings() {
  const [version, setVersion] = useState("");
  const [downloadUrl, setDownloadUrl] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string } | null>(null);

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      const client = await getArchipelagoClient();
      if (cancelled) return;
      if (client) {
        setVersion(client.version);
        setDownloadUrl(client.downloadUrl);
      }
      setLoading(false);
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  async function save() {
    setSaving(true);
    setMessage(null);
    const ok = await saveArchipelagoClient(version, downloadUrl);
    setMessage(
      ok
        ? { tone: "ok", text: "Client Archipelago enregistré." }
        : { tone: "error", text: "Échec : une version et une URL http(s) valides sont requises." },
    );
    setSaving(false);
  }

  return (
    <section className="rounded-lg border border-border bg-surface p-6">
      <h2 className="font-heading text-xl font-semibold text-foreground">Client Archipelago</h2>
      <p className="mt-1 text-sm text-muted-foreground">
        Version et lien affichés aux joueurs comme la version du client à installer.
      </p>

      {loading ? (
        <p className="mt-4 flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden="true" className="size-4 animate-spin" /> Chargement…
        </p>
      ) : (
        <div className="mt-5 grid gap-4 sm:grid-cols-2">
          <label className="grid gap-1.5 text-sm">
            <span className="font-medium text-foreground">Version</span>
            <input
              className="min-h-10 rounded border border-border bg-background px-3 text-sm outline-none focus:border-accent"
              onChange={(e) => setVersion(e.target.value)}
              placeholder="0.5.1"
              type="text"
              value={version}
            />
          </label>
          <label className="grid gap-1.5 text-sm">
            <span className="font-medium text-foreground">URL de téléchargement</span>
            <input
              className="min-h-10 rounded border border-border bg-background px-3 text-sm outline-none focus:border-accent"
              onChange={(e) => setDownloadUrl(e.target.value)}
              placeholder="https://github.com/ArchipelagoMW/Archipelago/releases/latest"
              type="url"
              value={downloadUrl}
            />
          </label>
          <div className="flex flex-wrap items-center gap-3 sm:col-span-2">
            <button
              className="inline-flex min-h-10 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
              disabled={saving}
              onClick={save}
              type="button"
            >
              {saving ? "Enregistrement…" : "Enregistrer"}
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
