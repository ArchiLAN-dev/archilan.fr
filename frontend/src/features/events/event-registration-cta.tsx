"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

type CtaState =
  | { kind: "loading" }
  | { kind: "guest" }
  | { kind: "registered"; registrationId: string }
  | { kind: "not_registered" };

export function EventRegistrationCta({
  eventId,
  eventSlug,
}: {
  eventId: string;
  eventSlug: string;
}) {
  const [state, setState] = useState<CtaState>({ kind: "loading" });

  useEffect(() => {
    let cancelled = false;

    async function run() {
      const profileRes = await apiFetch(`${env.apiBaseUrl}/account/profile`);

      if (cancelled) return;

      if (profileRes.status === 401 || profileRes.status === 403) {
        setState({ kind: "guest" });
        return;
      }

      const regRes = await apiFetch(`${env.apiBaseUrl}/events/${eventId}/my-registration`);

      if (cancelled) return;

      if (!regRes.ok) {
        setState({ kind: "not_registered" });
        return;
      }

      const payload: unknown = await regRes.json();
      const registrationId =
        payload &&
        typeof payload === "object" &&
        "data" in payload &&
        typeof (payload as { data: unknown }).data === "object" &&
        (payload as { data: unknown }).data !== null &&
        "registrationId" in ((payload as { data: unknown }).data as object)
          ? ((payload as { data: { registrationId: string } }).data.registrationId)
          : null;

      if (!registrationId) {
        setState({ kind: "not_registered" });
        return;
      }

      setState({ kind: "registered", registrationId });
    }

    void run().catch(() => {
      if (!cancelled) setState({ kind: "not_registered" });
    });

    return () => {
      cancelled = true;
    };
  }, [eventId]);

  if (state.kind === "loading") {
    return <div className="h-12 w-full animate-pulse rounded bg-surface" />;
  }

  if (state.kind === "registered") {
    return (
      <Link
        className="inline-flex min-h-12 w-full items-center justify-center rounded bg-accent px-5 font-semibold text-white transition-colors hover:bg-accent-hover"
        href={`/evenements/${eventSlug}/inscription/${state.registrationId}/recap`}
      >
        Modifier mon inscription
      </Link>
    );
  }

  return (
    <Link
      className="inline-flex min-h-12 w-full items-center justify-center rounded bg-accent px-5 font-semibold text-white transition-colors hover:bg-accent-hover"
      href={`/evenements/${eventSlug}/inscription`}
    >
      S&apos;inscrire à cet événement
    </Link>
  );
}
