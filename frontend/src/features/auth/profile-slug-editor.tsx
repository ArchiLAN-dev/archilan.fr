"use client";

import { AlertCircle, Check, Link2 } from "lucide-react";
import Link from "next/link";
import { useEffect, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchAccountSlugState, type AccountSlugState } from "./auth-api";

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

type ProfileSlugEditorProps = {
  onDirtyChange?: (dirty: boolean) => void;
  registerSave?: (save: () => Promise<boolean>) => void;
};

export function ProfileSlugEditor({ onDirtyChange, registerSave }: ProfileSlugEditorProps = {}) {
  const queryClient = useQueryClient();
  const [value, setValue] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  // fetchAccountSlugState resolves to null on error (never throws) and already computed the cooldown
  // at fetch time, so Date.now() stays out of render. Retry off like the old one-shot effect.
  const { data: slugState, isLoading: loading } = useQuery({
    queryKey: ["account-slug"],
    queryFn: fetchAccountSlugState,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  const slug = slugState?.slug ?? null;
  // cooldownUntil is null unless the cooldown was still in the future at fetch time.
  const cooldownUntil = slugState?.cooldownUntil ?? null;

  // Seed the editable input from the fetched slug. Keyed on data identity: structural sharing keeps the
  // object stable across refetches with identical content, so this only re-runs when the server slug
  // actually changed. The draft diverges from server state while typing - not derivable during render.
  useEffect(() => {
    if (slugState) {
      // eslint-disable-next-line react-hooks/set-state-in-effect -- seeding the local input draft from query data; see comment above
      setValue(slugState.slug ?? "");
    }
  }, [slugState]);
  const candidate = value.trim().toLowerCase();
  const dirty = candidate !== (slug ?? "");
  // Only count as dirty (savable) when the URL is actually editable and non-empty.
  const savableDirty = dirty && cooldownUntil === null && candidate !== "";

  async function handleSave(): Promise<boolean> {
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
        return false;
      }
      const newSlug = json.data?.slug ?? candidate;
      // Reflect the new slug immediately, then let a background refetch pick up the fresh cooldown.
      const nextState: AccountSlugState = { slug: newSlug, cooldownUntil };
      queryClient.setQueryData(["account-slug"], nextState);
      setValue(newSlug);
      setSaved(true);
      void queryClient.invalidateQueries({ queryKey: ["account-slug"] });
      return true;
    } catch {
      setError("Impossible de contacter l'API.");
      return false;
    } finally {
      setSaving(false);
    }
  }

  // Surface dirty state + the save handler to the shared save bar (parent orchestrator).
  useEffect(() => {
    onDirtyChange?.(savableDirty);
  }, [savableDirty, onDirtyChange]);

  useEffect(() => {
    registerSave?.(handleSave);
  });

  const header = (
    <div className="flex flex-wrap items-center justify-between gap-2">
      <p className="text-sm text-muted-foreground">Personnalise ton profil public.</p>
      {slug ? (
        <Link className="text-sm font-medium text-accent-text hover:text-accent-text-hover" href={`/joueurs/${slug}`}>
          Voir mon profil →
        </Link>
      ) : null}
    </div>
  );

  if (loading) {
    return (
      <>
        {header}
        <div aria-hidden className="h-28 animate-pulse rounded-xl border border-border bg-surface" />
      </>
    );
  }

  return (
    <>
      {header}
      <section className="grid gap-3 rounded-xl border border-border bg-surface p-5">
      <div className="flex items-center gap-2">
        <Link2 aria-hidden className="size-4 text-accent-text" />
        <h2 className="font-heading text-sm font-semibold text-foreground">URL de profil</h2>
      </div>

      <label className="grid gap-1.5">
        <span className="text-xs text-muted-foreground">Ton adresse publique</span>
        <div className="flex items-center gap-0 overflow-hidden rounded-lg border border-border bg-background focus-within:border-accent">
          <span className="shrink-0 select-none border-r border-border px-3 py-2 text-sm text-muted-foreground">{env.appUrl}/joueurs/</span>
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

      {(saved || error !== null) && (
        <div className="flex flex-wrap items-center gap-3">
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
      )}
      </section>
    </>
  );
}
