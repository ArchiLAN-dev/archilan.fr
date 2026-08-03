"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Loader2, Play } from "lucide-react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { useAuth } from "@/features/auth/auth-context";

/** The API caps a run title at 80 characters (PersonalRunDrafts::create). */
const MAX_TITLE = 80;

type Props = {
  gameId: string;
  gameName: string;
  gameSlug: string;
};

/**
 * Starts a personal run with this game already selected (story 17.23).
 *
 * Client-side on purpose: the game page is served from the ISR cache
 * (`revalidate = 300`), so nothing user-specific may be rendered on the server -
 * the very first visitor's state would be baked into the HTML served to everyone
 * else. Authentication is therefore resolved in the browser.
 *
 * The game travels with the creation call rather than in a second request: the
 * API validates it *before* creating anything, so a game that cannot be added
 * never leaves an empty run behind.
 */
export function CreateRunWithGameButton({ gameId, gameName, gameSlug }: Props) {
  const { user, loading } = useAuth();
  const router = useRouter();
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function onClick() {
    if (!user) {
      router.push(`/connexion?returnTo=${encodeURIComponent(`/jeux/${gameSlug}`)}`);
      return;
    }

    setError(null);
    setCreating(true);

    try {
      const res = await apiFetch(`${env.apiBaseUrl}/runs`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ title: `Partie ${gameName}`.slice(0, MAX_TITLE), gameId }),
      });

      if (!res.ok) {
        const payload = (await res.json()) as { error?: { details?: { gameId?: string[]; title?: string[] } } };
        // The server owns the reason a game cannot be added (disabled, apworld preflight failure):
        // surface its message rather than guessing one here.
        setError(
          payload.error?.details?.gameId?.[0] ??
            payload.error?.details?.title?.[0] ??
            "Impossible de créer la partie.",
        );
        return;
      }

      const payload = (await res.json()) as { data: { id: string } };
      router.push(`/runs/${payload.data.id}`);
    } catch {
      setError("Erreur réseau.");
    } finally {
      setCreating(false);
    }
  }

  if (loading) {
    return <div aria-hidden className="h-10 w-56 animate-pulse rounded-lg bg-surface" />;
  }

  return (
    <div className="grid gap-2">
      <button
        className="inline-flex min-h-10 w-fit items-center gap-2 rounded-lg border border-accent bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
        disabled={creating}
        onClick={onClick}
        type="button"
      >
        {creating ? (
          <Loader2 aria-hidden="true" className="size-4 animate-spin" />
        ) : (
          <Play aria-hidden="true" className="size-4" />
        )}
        {creating ? "Création…" : "Créer une partie avec ce jeu"}
      </button>
      {!user ? (
        <p className="text-xs text-muted-foreground">Connexion requise - on te ramène ici ensuite.</p>
      ) : null}
      {error !== null ? <p className="text-xs text-danger">{error}</p> : null}
    </div>
  );
}
