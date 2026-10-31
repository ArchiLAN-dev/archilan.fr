"use client";

import { use, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Cast } from "lucide-react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { broadcastConsentKey, fetchBroadcastConsent, saveBroadcastConsent, type BroadcastConsent } from "./broadcast-consent-api";

type Props = { params: Promise<{ registrationId: string }> };

/** Story 47.1: on the member's own registration, whether the ArchiLAN broadcast may show their Twitch stream. */
export function BroadcastConsentCard({ params }: Props) {
  const { registrationId } = use(params);
  const { data } = useQuery({
    queryKey: broadcastConsentKey(registrationId),
    queryFn: () => fetchBroadcastConsent(registrationId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  // Signed out, someone else's registration, cancelled seat or event over: nothing to choose.
  if (!data?.editable) return null;
  return <BroadcastConsentForm consent={data.consent} registrationId={registrationId} />;
}

export function BroadcastConsentForm({ consent, registrationId }: { consent: boolean; registrationId: string }) {
  const queryClient = useQueryClient();
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function change(next: boolean) {
    setPending(true);
    setError(null);
    const failure = await saveBroadcastConsent(registrationId, next);
    setPending(false);
    if (failure !== null) {
      setError(failure);
      return;
    }
    const saved: BroadcastConsent = { consent: next, editable: true };
    queryClient.setQueryData(broadcastConsentKey(registrationId), saved);
  }

  return (
    <section aria-labelledby="broadcast-consent-heading" className="mt-8 grid gap-3 rounded-lg border border-border bg-surface p-5">
      <h2 className="flex items-center gap-2 font-heading text-lg font-bold text-foreground" id="broadcast-consent-heading">
        <Cast aria-hidden="true" className="size-5 text-accent-text" />
        Ton stream sur la régie
      </h2>
      <label className="flex items-start gap-3 text-sm text-foreground">
        <input
          checked={consent}
          className="mt-0.5 size-4 accent-accent"
          disabled={pending}
          onChange={(event) => void change(event.target.checked)}
          type="checkbox"
        />
        <span>La régie ArchiLAN peut diffuser mon stream Twitch pendant l&apos;événement</span>
      </label>
      <p className="text-xs text-muted-foreground">
        Si ta chaîne Twitch est dans les liens de ton profil, la régie peut montrer ton stream pendant les pauses. Décoche
        si tu préfères ne pas passer à l&apos;antenne, tu peux changer d&apos;avis jusqu&apos;à la fin de l&apos;événement.
      </p>
      {error ? (
        <p className="text-sm text-danger" role="alert">
          {error}
        </p>
      ) : null}
    </section>
  );
}
