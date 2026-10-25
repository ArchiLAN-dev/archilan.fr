"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { Loader2, Music } from "lucide-react";

import { useAuth } from "@/features/auth/auth-context";
import { requestBlindTestAnimation } from "./blind-test-api";

type Props = { state: string };

/** The page the app sends an animator to: back to the login page first when signed out, with this very address. */
export function blindTestAnimationReturnTo(state: string): string {
  return `/blind-test/animation?${new URLSearchParams({ state }).toString()}`;
}

/**
 * Story 41.53: the site's half of the animators' login. A signed-in admin gets a one-time code and their browser goes
 * straight back to the app's console; anyone else is told the animation is for admins. The return address comes from
 * the server, never from this page's URL.
 */
export function BlindTestAnimationBridge({ state }: Props) {
  const { user, loading } = useAuth();
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);
  const started = useRef(false);

  useEffect(() => {
    if (loading) return;
    if (!user) {
      router.replace(`/connexion?returnTo=${encodeURIComponent(blindTestAnimationReturnTo(state))}`);
      return;
    }
    if (started.current) return;
    started.current = true;
    void requestBlindTestAnimation(state).then((result) => {
      if (result.kind === "ok") {
        window.location.assign(result.redirect);
      } else {
        setError(result.message);
      }
    });
  }, [loading, user, router, state]);

  return (
    <section className="card-glow mx-auto grid max-w-md justify-items-center gap-3 rounded-lg border border-border p-8 text-center">
      <Music aria-hidden className="size-8 text-accent-text" />
      <h1 className="font-heading text-xl font-semibold text-foreground">Animation du blind test</h1>
      {error === null ? (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden className="size-4 animate-spin" />
          Connexion à la console…
        </p>
      ) : (
        <p className="text-sm text-danger" role="alert">
          {error}
        </p>
      )}
    </section>
  );
}
