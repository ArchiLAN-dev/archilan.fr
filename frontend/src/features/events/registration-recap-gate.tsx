"use client";

import Link from "next/link";
import { use, useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { AlertCircle, CheckCircle, XCircle } from "lucide-react";

import { RegistrationStepper } from "@/features/events/registration-stepper";
import { RegistrationSurveysRecap, useRegistrationSurveys } from "@/features/surveys/registration-questions";
import { SlotNeedsReview } from "@/features/games/slot-needs-review";
import { ConfigureSlotYamlLink, resolveSlotYamlStatus, SlotYamlStatusBadge } from "@/features/games/slot-yaml-status";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { useRouter } from "next/navigation";
import { DiscordNudge } from "@/features/discord/discord-promo";
import {
  fetchAuthProbe,
  fetchRegistrationRecap,
  type RecapSlot,
  type RegistrationRecapResult,
} from "./events-api";

// ─── Types ───────────────────────────────────────────────────────────────────

// "error" results are thrown inside the queryFn so a failed background refetch keeps the
// last good data instead of blanking the page.
type RecapQueryData = Exclude<RegistrationRecapResult, { kind: "error" }>;

type SubmitState =
  | { kind: "idle" }
  | { kind: "submitting" }
  | { kind: "confirmed"; eventTitle: string; slots: RecapSlot[] }
  | { kind: "error"; message: string };

// ─── Main gate ───────────────────────────────────────────────────────────────

export function RegistrationRecapGate({
  params,
}: {
  params: Promise<{ eventSlug: string; registrationId: string }>;
}) {
  const router = useRouter();
  const { eventSlug, registrationId } = use(params);
  const [submitState, setSubmitState] = useState<SubmitState>({ kind: "idle" });

  // Fresh session probe on every gate entry (staleTime 0), like the pre-TanStack effect.
  const authQuery = useQuery({
    queryKey: ["registration-auth-probe"],
    queryFn: fetchAuthProbe,
    staleTime: 0,
    retry: false,
  });

  useEffect(() => {
    if (authQuery.data === "unauthenticated") {
      router.replace(`/connexion?returnTo=/evenements/${eventSlug}/inscription/${registrationId}/recap`);
    }
  }, [authQuery.data, eventSlug, registrationId, router]);

  const recapQuery = useQuery({
    queryKey: ["registration-recap", registrationId],
    queryFn: async (): Promise<RecapQueryData> => {
      const result = await fetchRegistrationRecap(registrationId);
      if (result.kind === "error") throw new Error(result.message);
      return result;
    },
    enabled: authQuery.data === "authenticated",
    // Funnel steps write to each other's data (selection save, yaml edits): refetch on
    // every mount like the pre-TanStack effect did.
    staleTime: 0,
    retry: false,
  });

  const recapData = recapQuery.data?.kind === "success" ? recapQuery.data.data : null;
  // Story 51.5: the step and the answers of the organisers' questions at registration.
  const registrationSurveys = useRegistrationSurveys(eventSlug);
  const withQuestions = registrationSurveys !== undefined && registrationSurveys.length > 0;

  async function handleSubmit() {
    if (recapData === null) return;
    setSubmitState({ kind: "submitting" });
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/registrations/${registrationId}/submit`, {
        method: "POST",
      });

      if (res.status === 422) {
        const body = (await res.json()) as { error?: { message?: string } };
        setSubmitState({
          kind: "error",
          message: body.error?.message ?? "Impossible de confirmer l'inscription.",
        });
        return;
      }

      if (!res.ok) {
        setSubmitState({ kind: "error", message: "Impossible de confirmer l'inscription." });
        return;
      }

      const body = (await res.json()) as { data?: { eventTitle?: string } };
      setSubmitState({
        kind: "confirmed",
        eventTitle: body.data?.eventTitle ?? recapData.eventTitle,
        slots: recapData.slots,
      });
    } catch {
      setSubmitState({ kind: "error", message: "Impossible de contacter l'API." });
    }
  }

  // The API is unreachable (auth probe network failure) - same terminal error as before.
  if (authQuery.data === null) {
    return (
      <div className="grid gap-4 card-glow rounded-lg border border-border p-8 text-center">
        <AlertCircle aria-hidden="true" className="mx-auto size-8 text-danger" />
        <p className="font-heading text-xl font-semibold text-foreground">Erreur</p>
        <p className="text-sm text-muted-foreground">Impossible de contacter l&apos;API.</p>
      </div>
    );
  }

  const gateResult = recapQuery.data;

  if (gateResult === undefined) {
    if (recapQuery.isError) {
      const message =
        recapQuery.error instanceof Error
          ? recapQuery.error.message
          : "Impossible de contacter l'API.";
      return (
        <div className="grid gap-4 card-glow rounded-lg border border-border p-8 text-center">
          <AlertCircle aria-hidden="true" className="mx-auto size-8 text-danger" />
          <p className="font-heading text-xl font-semibold text-foreground">Erreur</p>
          <p className="text-sm text-muted-foreground">{message}</p>
        </div>
      );
    }

    return (
      <div aria-hidden="true" className="grid gap-8">
        {/* stepper */}
        <div className="flex items-center gap-2">
          {[0, 1, 2].map((i) => (
            <div className="flex items-center gap-2" key={i}>
              <div className="size-7 animate-pulse rounded-full bg-surface-2" />
              {i < 2 && <div className="h-px w-12 animate-pulse bg-surface-2" />}
            </div>
          ))}
        </div>
        {/* header */}
        <div className="grid gap-2">
          <div className="h-8 w-40 animate-pulse rounded bg-surface-2" />
          <div className="h-4 w-36 animate-pulse rounded bg-surface-2" />
        </div>
        {/* slot list */}
        <div className="grid gap-3">
          <div className="h-5 w-36 animate-pulse rounded bg-surface-2" />
          {[0, 1, 2].map((i) => (
            <div className="flex items-center justify-between rounded-lg border border-border p-4" key={i}>
              <div className="grid gap-1.5">
                <div className="h-4 w-32 animate-pulse rounded bg-surface-2" />
                <div className="h-3 w-20 animate-pulse rounded bg-surface-2" />
              </div>
              <div className="h-8 w-20 animate-pulse rounded bg-surface-2" />
            </div>
          ))}
        </div>
        {/* submit button */}
        <div className="h-11 w-48 animate-pulse rounded bg-surface-2" />
      </div>
    );
  }

  if (gateResult.kind === "not_found") {
    return (
      <div className="grid gap-4 card-glow rounded-lg border border-border p-8 text-center">
        <XCircle aria-hidden="true" className="mx-auto size-8 text-danger" />
        <p className="font-heading text-xl font-semibold text-foreground">Inscription introuvable</p>
        <p className="text-sm text-muted-foreground">
          Cette inscription n&apos;existe pas ou n&apos;est plus accessible.
        </p>
        <Link className="text-sm text-accent-text hover:text-accent-text-hover" href="/evenements">
          Voir tous les événements
        </Link>
      </div>
    );
  }

  if (submitState.kind === "confirmed") {
    return (
      <ConfirmationScreen
        eventSlug={eventSlug}
        eventTitle={submitState.eventTitle}
        slots={submitState.slots}
      />
    );
  }

  const { data } = gateResult;

  // Build slot labels (handle duplicates)
  const slotCountPerGame: Record<string, number> = {};
  for (const s of data.slots) {
    slotCountPerGame[s.gameId] = (slotCountPerGame[s.gameId] ?? 0) + 1;
  }
  const slotProgress: Record<string, number> = {};
  const labeledSlots = data.slots.map((slot) => {
    slotProgress[slot.gameId] = (slotProgress[slot.gameId] ?? 0) + 1;
    const n = slotProgress[slot.gameId];
    const total = slotCountPerGame[slot.gameId] ?? 1;
    const label = total > 1 ? `${slot.gameName} (monde ${n})` : slot.gameName;
    return { slot, label };
  });

  return (
    <article className="grid gap-8">
      <RegistrationStepper currentStep={withQuestions ? 3 : 2} withQuestions={withQuestions} />

      <header className="grid gap-2">
        <h1 className="font-heading text-3xl font-bold leading-tight text-foreground">
          Récapitulatif
        </h1>
        <p className="text-sm text-muted-foreground">{data.eventTitle}</p>
      </header>

      {/* Story 4.22: reserved is not confirmed; the member must see which one they are. */}
      {data.submittedAt !== null ? (
        <p className="flex items-center gap-2 rounded-lg border border-success/40 p-4 text-sm text-foreground">
          <CheckCircle aria-hidden="true" className="size-4 shrink-0 text-success" />
          Ton inscription est confirmée. Tu peux encore modifier tes jeux tant que les inscriptions sont ouvertes.
        </p>
      ) : (
        <p className="flex items-center gap-2 rounded-lg border border-accent-warm/50 p-4 text-sm text-foreground">
          <AlertCircle aria-hidden="true" className="size-4 shrink-0 text-accent-warm" />
          Ton inscription n&apos;est pas encore confirmée : vérifie tes jeux puis clique sur « Confirmer l&apos;inscription ».
        </p>
      )}

      <RegistrationSurveysRecap eventSlug={eventSlug} registrationId={registrationId} />

      {data.gameSelectionEnabled ? (
        <section className="grid gap-4">
          <h2 className="font-heading text-xl font-semibold text-foreground">Jeux sélectionnés</h2>

          {data.slots.length === 0 ? (
            <div className="card-glow rounded-lg border border-border p-5">
              <p className="text-sm text-muted-foreground">Aucun jeu sélectionné.</p>
              <Link
                className="mt-3 inline-flex text-sm text-accent-text hover:text-accent-text-hover"
                href={`/evenements/${eventSlug}/inscription/${registrationId}/jeux`}
              >
                Sélectionner des jeux →
              </Link>
            </div>
          ) : (
            <ul className="divide-y divide-border rounded-lg border border-border" role="list">
              {labeledSlots.map(({ slot, label }) => {
                const game = data.gameMap.get(slot.gameId);
                const yamlStatus = resolveSlotYamlStatus(slot, game);
                return (
                  <li
                    key={slot.slotId}
                    className="flex items-center gap-3 bg-background px-4 py-3 first:rounded-t-lg last:rounded-b-lg transition-colors hover:bg-surface"
                  >
                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-semibold leading-tight text-foreground">{label}</p>
                      <div className="mt-1">
                        <SlotYamlStatusBadge status={yamlStatus} />
                      </div>
                      <SlotNeedsReview reasons={slot.needsReview} />
                    </div>
                    {game?.isApworldReady ? (
                      <ConfigureSlotYamlLink
                        href={`/evenements/${eventSlug}/inscription/${registrationId}/slots/${slot.slotId}`}
                        label={label}
                      />
                    ) : null}
                  </li>
                );
              })}
            </ul>
          )}

          {data.registrationOpen && data.slots.length > 0 ? (
            <p className="text-xs text-muted-foreground">
              Si tu ne configures pas les options d&apos;un jeu, les valeurs par défaut seront utilisées.
            </p>
          ) : null}
        </section>
      ) : null}

      <div className="grid gap-3">
        {submitState.kind === "error" ? (
          <p className="flex items-center gap-2 text-sm text-danger">
            <AlertCircle aria-hidden="true" className="size-4 shrink-0" />
            {submitState.message}
          </p>
        ) : null}

        <div className="grid grid-cols-1 gap-2 sm:flex sm:flex-wrap sm:gap-3">
          {data.registrationOpen && data.submittedAt === null ? (
            <button
              className="inline-flex min-h-12 w-full items-center justify-center rounded bg-accent px-6 font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
              disabled={submitState.kind === "submitting"}
              type="button"
              onClick={() => { void handleSubmit(); }}
            >
              {submitState.kind === "submitting" ? "Confirmation…" : "Confirmer l'inscription"}
            </button>
          ) : null}
          <Link
            className="inline-flex min-h-12 w-full items-center justify-center rounded border border-accent px-6 font-semibold text-accent-text transition-colors hover:bg-accent/10 sm:w-auto"
            href={`/evenements/${eventSlug}/inscription/${registrationId}/jeux`}
          >
            Modifier ma sélection
          </Link>
        </div>
      </div>
    </article>
  );
}

// ─── Confirmation screen ──────────────────────────────────────────────────────

function ConfirmationScreen({
  eventSlug,
  eventTitle,
  slots,
}: {
  eventSlug: string;
  eventTitle: string;
  slots: RecapSlot[];
}) {
  return (
    <div className="grid gap-8">
      <div className="grid gap-4 card-glow rounded-lg border border-border p-8 text-center">
        <CheckCircle aria-hidden="true" className="mx-auto size-12 text-success" />
        <h1 className="font-heading text-2xl font-bold text-foreground">
          Inscription confirmée !
        </h1>
        <p className="text-muted-foreground">
          Ton inscription pour <strong>{eventTitle}</strong> a bien été enregistrée.
          À très bientôt !
        </p>
      </div>

      {/* Story 30.50: the next step is the community. */}
      <DiscordNudge cta="Rejoindre le Discord" title="Prochaine étape : rejoindre le Discord">
        Les annonces de l&apos;événement, la constitution des équipes et les questions de dernière minute passent par le
        Discord.
      </DiscordNudge>

      {slots.length > 0 ? (
        <section className="grid gap-3">
          <h2 className="font-heading text-lg font-semibold text-foreground">Tes jeux</h2>
          <ul className="divide-y divide-border rounded-lg border border-border" role="list">
            {slots.map((slot) => (
              <li key={slot.slotId} className="bg-background px-4 py-3 first:rounded-t-lg last:rounded-b-lg">
                <p className="text-sm font-semibold text-foreground">{slot.gameName}</p>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      <Link
        className="inline-flex min-h-11 w-full items-center justify-center rounded border border-border px-6 text-sm font-semibold text-foreground transition-colors hover:border-accent hover:text-accent-text sm:w-fit"
        href={`/evenements/${eventSlug}`}
      >
        Retour à l&apos;événement
      </Link>
    </div>
  );
}
