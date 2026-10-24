"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { Loader2, Music } from "lucide-react";

import { useAuth } from "@/features/auth/auth-context";
import { requestBlindTestLogin } from "./blind-test-api";

type Props = { eventId: string; state: string };

/** The page the app sends a member to: back to the login page first when signed out, with this very address. */
export function blindTestLoginReturnTo(eventId: string, state: string): string {
  return `/blind-test/connexion?${new URLSearchParams({ event: eventId, state }).toString()}`;
}

/**
 * Story 41.45: the site's half of the blind test login. A signed-in member who may play gets a one-time code and their
 * browser goes straight back to the app; the return address comes from the server, never from this page's URL.
 */
export function BlindTestLoginBridge({ eventId, state }: Props) {
  const { user, loading } = useAuth();
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);
  const started = useRef(false);

  useEffect(() => {
    if (loading) return;
    if (!user) {
      router.replace(`/connexion?returnTo=${encodeURIComponent(blindTestLoginReturnTo(eventId, state))}`);
      return;
    }
    if (started.current) return;
    started.current = true;
    void requestBlindTestLogin(eventId, state).then((result) => {
      if (result.kind === "ok") {
        window.location.assign(result.redirect);
      } else {
        setError(result.message);
      }
    });
  }, [loading, user, router, eventId, state]);

  return (
    <section className="card-glow mx-auto grid max-w-md justify-items-center gap-3 rounded-lg border border-border p-8 text-center">
      <Music aria-hidden className="size-8 text-accent-text" />
      <h1 className="font-heading text-xl font-semibold text-foreground">Blind test</h1>
      {error === null ? (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden className="size-4 animate-spin" />
          Connexion au blind test…
        </p>
      ) : (
        <p className="text-sm text-danger" role="alert">
          {error}
        </p>
      )}
    </section>
  );
}
