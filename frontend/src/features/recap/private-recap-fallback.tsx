"use client";

import { useQuery } from "@tanstack/react-query";

import { useAuth } from "@/features/auth/auth-context";
import { fetchSessionFeed } from "@/features/recap/feed-api";
import { fetchSessionRecap } from "@/features/recap/recap-api";
import { SessionRecapNotFound, SessionRecapView } from "@/features/recap/session-recap-page";

const STALE_TIME = 60_000;

/**
 * Client-side recovery for a recap the SSR fetch could not serve (story 32.5).
 *
 * The recap page renders server-side, but a private personal-run recap is only visible to its
 * owner/participants - and their `__Host-archilan_session` cookie is host-bound to the API subdomain,
 * so it never reaches the frontend's server-side fetch. This runs in the browser, where apiFetch talks
 * to the API directly and carries that cookie, so an authenticated owner/participant loads their
 * private recap here. An anonymous viewer has no private access to try, so we go straight to not-found.
 */
export function PrivateRecapFallback({ sessionId }: { sessionId: string }) {
  const { user, loading } = useAuth();
  const authenticated = !loading && user !== null;

  const query = useQuery({
    queryKey: ["private-recap", sessionId],
    queryFn: async () => {
      const [recap, feed] = await Promise.all([fetchSessionRecap(sessionId), fetchSessionFeed(sessionId)]);
      return { recap, feed };
    },
    enabled: authenticated,
    staleTime: STALE_TIME,
  });

  if (loading || (authenticated && query.isPending)) {
    return <RecapLoading />;
  }

  if (!authenticated || !query.data?.recap) {
    return <SessionRecapNotFound />;
  }

  return <SessionRecapView feed={query.data.feed} recap={query.data.recap} />;
}

function RecapLoading() {
  return (
    <section className="mx-auto grid w-full max-w-content gap-4 px-4 py-16 text-center" aria-busy="true">
      <p className="text-sm text-muted-foreground">Chargement du récap…</p>
    </section>
  );
}
