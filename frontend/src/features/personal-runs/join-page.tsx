"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Loader2, Users } from "lucide-react";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { useAuth } from "@/features/auth/auth-context";

type PreviewData = {
  title: string;
  ownerName: string | null;
  participantCount: number;
  status: string;
};

type PageState =
  | { kind: "loading" }
  | { kind: "not_found" }
  | { kind: "preview"; data: PreviewData }
  | { kind: "joining" }
  | { kind: "error"; message: string };

export function JoinPage({ inviteToken }: { inviteToken: string }) {
  const { user, loading: authLoading } = useAuth();
  const router = useRouter();
  const [state, setState] = useState<PageState>({ kind: "loading" });
  const joinInitiated = useRef(false);

  useEffect(() => {
    let mounted = true;

    async function loadPreview() {
      try {
        const res = await fetch(`${env.apiBaseUrl}/runs/invite/${inviteToken}/preview`);
        if (!mounted) return;

        if (res.status === 404) {
          setState({ kind: "not_found" });
          return;
        }

        if (!res.ok) {
          setState({ kind: "error", message: "Impossible de charger les informations de la partie." });
          return;
        }

        const payload = (await res.json()) as { data: PreviewData };
        setState({ kind: "preview", data: payload.data });
      } catch {
        if (mounted) setState({ kind: "error", message: "Erreur réseau." });
      }
    }

    void loadPreview();
    return () => { mounted = false; };
  }, [inviteToken]);

  // Authenticated: auto-join once preview is ready, guarded by a ref to prevent re-runs
  useEffect(() => {
    if (authLoading || !user || state.kind !== "preview" || joinInitiated.current) return;

    joinInitiated.current = true;

    async function join() {
      setState({ kind: "joining" });
      try {
        const res = await apiFetch(`${env.apiBaseUrl}/runs/join/${inviteToken}`);
        if (res.status === 404) { setState({ kind: "not_found" }); return; }
        if (!res.ok) { setState({ kind: "error", message: "Impossible de rejoindre la partie." }); return; }
        const payload = (await res.json()) as { data: { id: string } };
        router.replace(`/runs/${payload.data.id}`);
      } catch {
        setState({ kind: "error", message: "Erreur réseau." });
      }
    }

    void join();
  }, [authLoading, user, state.kind, inviteToken, router]);

  const returnTo = `/runs/join/${inviteToken}`;

  if (authLoading || state.kind === "loading") {
    return (
      <div className="flex min-h-[50vh] items-center justify-center">
        <Loader2 aria-hidden className="size-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (state.kind === "not_found") {
    return (
      <div className="mx-auto max-w-lg py-16 text-center">
        <p className="mb-2 font-heading text-xl font-bold text-foreground">
          Lien invalide ou partie annulée
        </p>
        <p className="mb-6 text-muted-foreground">
          Ce lien d&apos;invitation n&apos;est plus valide ou la partie a été annulée.
        </p>
        <Link
          className="inline-flex items-center rounded border border-border px-4 py-2 text-sm font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
          href="/"
        >
          Retour à l&apos;accueil
        </Link>
      </div>
    );
  }

  if (state.kind === "error") {
    return (
      <div className="mx-auto max-w-lg py-16 text-center">
        <p className="mb-2 font-heading text-xl font-bold text-foreground">Une erreur est survenue</p>
        <p className="mb-6 text-muted-foreground">{state.message}</p>
        <Link
          className="inline-flex items-center rounded border border-border px-4 py-2 text-sm font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
          href="/"
        >
          Retour à l&apos;accueil
        </Link>
      </div>
    );
  }

  if (state.kind === "joining") {
    return (
      <div className="flex min-h-[50vh] items-center justify-center">
        <div className="text-center">
          <Loader2 aria-hidden className="mx-auto mb-3 size-8 animate-spin text-accent-text" />
          <p className="text-sm text-muted-foreground">Connexion à la partie en cours…</p>
        </div>
      </div>
    );
  }

  // Preview available - unauthenticated or authenticated (joining in progress handled above)
  const { data } = state;

  return (
    <div className="mx-auto max-w-lg py-12">
      <div className="rounded-lg border border-border bg-surface p-8">
        <div className="mb-6 text-center">
          <p className="mb-2 text-sm font-semibold uppercase tracking-[0.18em] text-accent-text">
            Invitation
          </p>
          <h1 className="font-heading text-2xl font-bold text-foreground">{data.title}</h1>
          {data.ownerName && (
            <p className="mt-1 text-sm text-muted-foreground">
              Organisée par <span className="font-medium text-foreground">{data.ownerName}</span>
            </p>
          )}
        </div>

        <div className="mb-6 flex items-center justify-center gap-2 text-sm text-muted-foreground">
          <Users aria-hidden className="size-4" />
          <span>
            {data.participantCount === 0
              ? "Aucun participant pour l'instant"
              : `${data.participantCount} participant${data.participantCount > 1 ? "s" : ""}`}
          </span>
        </div>

        {/* Unauthenticated CTA */}
        {!user && (
          <div className="grid gap-3">
            <Link
              className="flex w-full items-center justify-center rounded bg-accent px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
              href={`/connexion?returnTo=${encodeURIComponent(returnTo)}`}
            >
              Se connecter pour rejoindre
            </Link>
            <Link
              className="flex w-full items-center justify-center rounded border border-border px-4 py-3 text-sm font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
              href={`/inscription?returnTo=${encodeURIComponent(returnTo)}`}
            >
              Créer un compte
            </Link>
          </div>
        )}
      </div>
    </div>
  );
}
