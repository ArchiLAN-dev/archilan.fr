"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { registrantHubPath } from "@/features/registrant-hub/registrant-hub-api";
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

  if (data?.kind === "registered") {
    return <RegisteredCta confirmed={data.confirmed} eventSlug={eventSlug} inProgress={inProgress} registrationId={data.registrationId} />;
  }

  // Story 4.23: nobody is offered to sign up once the event runs.
  if (inProgress) return null;

  return (
    <Link
      className="inline-flex min-h-12 w-full items-center justify-center rounded bg-accent px-5 font-semibold text-white transition-colors hover:bg-accent-hover"
      href={`/evenements/${eventSlug}/inscription`}
    >
      S&apos;inscrire à cet événement
    </Link>
  );
}

type RegisteredCtaProps = {
  eventSlug: string;
  registrationId: string;
  confirmed: boolean;
  inProgress: boolean;
};

/**
 * Story 53.7: a registrant is pointed to their space ("Mon event"), where the checklist, the codes and the practical
 * info wait; the registration (or, during the event, the game) stays one click away.
 */
export function RegisteredCta({ eventSlug, registrationId, confirmed, inProgress }: RegisteredCtaProps) {
  const registration = `/evenements/${eventSlug}/inscription/${registrationId}`;

  return (
    <div className="grid gap-3 rounded-xl border border-accent/40 bg-accent/5 p-4">
      <p className="text-sm text-foreground">
        <span className="font-semibold">Tu es inscrit.</span> Ton espace : ce qu&apos;il te reste à faire, tes codes, les infos
        pratiques.
      </p>
      <Link
        className="inline-flex min-h-12 w-full items-center justify-center rounded bg-accent px-5 font-semibold text-white transition-colors hover:bg-accent-hover"
        href={registrantHubPath(eventSlug)}
      >
        Mon event
      </Link>
      {inProgress ? (
        confirmed ? (
          <Link className="w-fit text-sm text-accent-text hover:text-accent-text-hover" href={`${registration}/session`}>
            Rejoindre ma partie
          </Link>
        ) : null
      ) : (
        <Link className="w-fit text-sm text-accent-text hover:text-accent-text-hover" href={`${registration}/recap`}>
          Modifier mon inscription
        </Link>
      )}
    </div>
  );
}
