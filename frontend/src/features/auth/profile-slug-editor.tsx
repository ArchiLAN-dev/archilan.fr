"use client";

import { AlertCircle, Check, Link2 } from "lucide-react";
import { useEffect, useState } from "react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

const ERROR_MESSAGES: Record<string, string> = {
  slug_invalid: "URL invalide : 3 à 30 caractères, minuscules, chiffres et tirets (pas d'espace ni accent).",
  slug_reserved_word: "Cette URL est réservée.",
  slug_taken: "Cette URL est déjà utilisée.",
  slug_reserved: "Cette URL a été libérée récemment ; elle reste réservée 30 jours à son ancien propriétaire.",
  slug_unchanged: "C'est déjà ton URL actuelle.",
};

function formatDate(iso: string): string {
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? iso
    : d.toLocaleDateString("fr-FR", { day: "numeric", month: "long", year: "numeric" });
}

export function ProfileSlugEditor() {
  const [slug, setSlug] = useState<string | null>(null);
  const [value, setValue] = useState("");
  const [nextAllowedAt, setNextAllowedAt] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      try {
        const res = await apiFetch(`${env.apiBaseUrl}/account/profile`);
        const json = (await res.json()) as { data?: { slug?: string | null; nextSlugChangeAllowedAt?: string | null } };
        if (cancelled) return;
        const s = json.data?.slug ?? null;
        setSlug(s);
        setValue(s ?? "");
        // Only keep the date if the cooldown is still in the future (Date.now() must stay out of render).
        const next = json.data?.nextSlugChangeAllowedAt ?? null;
        setNextAllowedAt(next !== null && new Date(next).getTime() > Date.now() ? next : null);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, []);

  // nextAllowedAt is already null unless the cooldown is in the future (computed at load).
  const cooldownUntil = nextAllowedAt;
  const candidate = value.trim().toLowerCase();
  const dirty = candidate !== (slug ?? "");

  async function handleSave() {
    setError(null);
    setSaved(false);
    setSaving(true);
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/account/slug`, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ slug: candidate }),
      });
      const json = (await res.json()) as {
        data?: { slug?: string };
        error?: { code?: string; details?: { nextAllowedAt?: string[] } };
      };
      if (!res.ok) {
        const code = json.error?.code ?? "slug_invalid";
        if (code === "slug_cooldown") {
          const next = json.error?.details?.nextAllowedAt?.[0];
          setError(`Tu as changé d'URL récemment. Prochain changement possible le ${next ? formatDate(next) : "bientôt"}.`);
        } else {
          setError(ERROR_MESSAGES[code] ?? ERROR_MESSAGES.slug_invalid);
        }
        return;
      }
      const newSlug = json.data?.slug ?? candidate;
      setSlug(newSlug);
      setValue(newSlug);
      setSaved(true);
    } catch {
      setError("Impossible de contacter l'API.");
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return <div aria-hidden className="h-28 animate-pulse rounded-xl border border-border bg-surface" />;
  }

  return (
    <section className="grid gap-3 rounded-xl border border-border bg-surface p-5">
      <div className="flex items-center gap-2">
        <Link2 aria-hidden className="size-4 text-accent-text" />
        <h2 className="font-heading text-sm font-semibold text-foreground">URL de profil</h2>
      </div>

      <label className="grid gap-1.5">
        <span className="text-xs text-muted-foreground">Ton adresse publique</span>
        <div className="flex items-center gap-0 overflow-hidden rounded-lg border border-border bg-background focus-within:border-accent">
          <span className="shrink-0 select-none border-r border-border px-3 py-2 text-sm text-muted-foreground">/joueurs/</span>
          <input
            className="min-h-9 w-full min-w-0 bg-transparent px-3 text-sm text-foreground outline-none disabled:cursor-not-allowed disabled:opacity-60"
            disabled={saving || cooldownUntil !== null}
            maxLength={30}
            onChange={(e) => { setValue(e.target.value); setError(null); setSaved(false); }}
            placeholder="ton-pseudo"
            value={value}
          />
        </div>
      </label>

      {cooldownUntil !== null ? (
        <p className="text-xs text-muted-foreground">
          Tu pourras changer d&apos;URL le {formatDate(cooldownUntil)} (1 changement tous les 30 jours).
        </p>
      ) : (
        <p className="text-xs text-muted-foreground">
          Minuscules, chiffres et tirets - 3 à 30 caractères. 1 changement tous les 30 jours ; l&apos;ancienne
          URL ne sera plus accessible.
        </p>
      )}

      <div className="flex flex-wrap items-center gap-3">
        <button
          className="inline-flex min-h-9 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50"
          disabled={saving || cooldownUntil !== null || !dirty || candidate === ""}
          onClick={() => { void handleSave(); }}
          type="button"
        >
          {saving ? "Enregistrement…" : "Changer mon URL"}
        </button>
        {saved && (
          <span className="inline-flex items-center gap-1.5 text-sm text-success">
            <Check aria-hidden className="size-4" />
            URL mise à jour
          </span>
        )}
        {error !== null && (
          <span className="inline-flex items-center gap-1.5 text-sm text-danger">
            <AlertCircle aria-hidden className="size-4" />
            {error}
          </span>
        )}
      </div>
    </section>
  );
}
