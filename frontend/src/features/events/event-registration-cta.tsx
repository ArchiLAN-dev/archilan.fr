"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchMyRegistrationCta } from "./events-api";

export function EventRegistrationCta({
  eventId,
  eventSlug,
  inProgress = false,
}: {
  eventId: string;
  eventSlug: string;
  /** Story 4.23: during the event, a confirmed registrant joins their game; nobody is offered to sign up. */
  inProgress?: boolean;
}) {
  // Guest-graceful: fetchMyRegistrationCta encodes the profile probe and the my-registration
  // lookup in a single result - a 401/403 is a guest (never a redirect) and any failure
  // degrades to the "not_registered" CTA, both of which render the same sign-up link below.
  const { data, isPending } = useQuery({
    queryKey: ["event-my-registration", eventId],
    queryFn: () => fetchMyRegistrationCta(eventId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (isPending) {
    return <div className="h-12 w-full animate-pulse rounded bg-surface" />;
  }

  if (inProgress) {
    if (data?.kind !== "registered" || !data.confirmed) return null;
    return (
      <Link
        className="inline-flex min-h-12 w-full items-center justify-center rounded bg-accent px-5 font-semibold text-white transition-colors hover:bg-accent-hover"
        href={`/evenements/${eventSlug}/inscription/${data.registrationId}/session`}
      >
        Rejoindre ma partie
      </Link>
    );
  }

  if (data?.kind === "registered") {
    return (
      <Link
        className="inline-flex min-h-12 w-full items-center justify-center rounded bg-accent px-5 font-semibold text-white transition-colors hover:bg-accent-hover"
        href={`/evenements/${eventSlug}/inscription/${data.registrationId}/recap`}
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
