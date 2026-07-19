"use client";

import Link from "next/link";
import { use, useEffect } from "react";
import { useQuery } from "@tanstack/react-query";
import { AlertCircle, ArrowLeft, Lock, XCircle } from "lucide-react";

import { YamlOptionEditor } from "@/features/events/yaml-option-editor";
import {
  fetchAuthProbe,
  fetchSlotYamlData,
  type SlotYamlResult,
} from "./events-api";

// ─── Types ───────────────────────────────────────────────────────────────────

// "error" results are thrown inside the queryFn so a failed background refetch keeps the
// last good data instead of blanking the page. A payload without this slot maps to
// "not_found" inside fetchSlotYamlData, exactly like the pre-TanStack parse.
type SlotYamlQueryData = Exclude<SlotYamlResult, { kind: "error" }>;

// ─── Main gate ───────────────────────────────────────────────────────────────

export function SlotYamlGate({
  params,
}: {
  params: Promise<{ eventSlug: string; registrationId: string; slotId: string }>;
}) {
  const { eventSlug, registrationId, slotId } = use(params);

  // Fresh session probe on every gate entry (staleTime 0), like the pre-TanStack effect.
  const authQuery = useQuery({
    queryKey: ["registration-auth-probe"],
    queryFn: fetchAuthProbe,
    staleTime: 0,
    retry: false,
  });

  useEffect(() => {
    if (authQuery.data === "unauthenticated") {
      window.location.href = `/connexion?returnTo=/evenements/${eventSlug}/inscription/${registrationId}/slots/${slotId}`;
    }
  }, [authQuery.data, eventSlug, registrationId, slotId]);

  const slotQuery = useQuery({
    queryKey: ["slot-yaml", registrationId, slotId],
    queryFn: async (): Promise<SlotYamlQueryData> => {
      const result = await fetchSlotYamlData(registrationId, slotId);
      if (result.kind === "error") throw new Error(result.message);
      return result;
    },
    enabled: authQuery.data === "authenticated",
    // Funnel steps write to each other's data: refetch on every mount like the
    // pre-TanStack effect did.
    staleTime: 0,
    retry: false,
    // The YAML editor below holds an unsaved draft seeded from this data: a focus-triggered
    // background refetch must not swap its inputs (the pre-TanStack gate never refetched).
    refetchOnWindowFocus: false,
  });

  const backHref = `/evenements/${eventSlug}/inscription/${registrationId}/recap`;

  // The API is unreachable (auth probe network failure) - same terminal error as before.
  if (authQuery.data === null) {
    return (
      <div className="grid gap-4 card-glow rounded-lg border border-border p-8 text-center">
        <AlertCircle aria-hidden="true" className="mx-auto size-8 text-danger" />
        <p className="font-heading text-xl font-semibold text-foreground">Erreur</p>
        <p className="text-sm text-muted-foreground">Impossible de contacter l&apos;API.</p>
        <Link className="text-sm text-accent-text hover:text-accent-text-hover" href={backHref}>
          Retour au récapitulatif
        </Link>
      </div>
    );
  }

  const gateResult = slotQuery.data;

  if (gateResult === undefined) {
    if (slotQuery.isError) {
      const message =
        slotQuery.error instanceof Error
          ? slotQuery.error.message
          : "Impossible de contacter l'API.";
      return (
        <div className="grid gap-4 card-glow rounded-lg border border-border p-8 text-center">
          <AlertCircle aria-hidden="true" className="mx-auto size-8 text-danger" />
          <p className="font-heading text-xl font-semibold text-foreground">Erreur</p>
          <p className="text-sm text-muted-foreground">{message}</p>
          <Link className="text-sm text-accent-text hover:text-accent-text-hover" href={backHref}>
            Retour au récapitulatif
          </Link>
        </div>
      );
    }

    return (
      <div aria-hidden="true" className="mx-auto grid max-w-3xl gap-8">
        {/* back button */}
        <div className="h-9 w-44 animate-pulse rounded border border-border bg-surface-2" />
        {/* header */}
        <div className="grid gap-3">
          <div className="h-3 w-24 animate-pulse rounded bg-surface-2" />
          <div className="h-7 w-52 animate-pulse rounded bg-surface-2" />
          <div className="h-4 w-40 animate-pulse rounded bg-surface-2" />
        </div>
        {/* yaml editor area */}
        <div className="grid gap-2">
          <div className="h-4 w-20 animate-pulse rounded bg-surface-2" />
          <div className="h-96 w-full animate-pulse rounded-lg border border-border bg-surface-2" />
        </div>
        {/* save button */}
        <div className="h-11 w-40 animate-pulse rounded bg-surface-2" />
      </div>
    );
  }

  if (gateResult.kind === "not_found") {
    return (
      <div className="grid gap-4 card-glow rounded-lg border border-border p-8 text-center">
        <XCircle aria-hidden="true" className="mx-auto size-8 text-danger" />
        <p className="font-heading text-xl font-semibold text-foreground">Slot introuvable</p>
        <p className="text-sm text-muted-foreground">
          Ce slot n&apos;existe pas ou n&apos;est plus accessible.
        </p>
        <Link className="text-sm text-accent-text hover:text-accent-text-hover" href={backHref}>
          Retour au récapitulatif
        </Link>
      </div>
    );
  }

  const { data } = gateResult;

  if (!data.game.isApworldReady) {
    return (
      <div className="grid gap-4 card-glow rounded-lg border border-border p-8 text-center">
        <AlertCircle aria-hidden="true" className="mx-auto size-8 text-muted-foreground" />
        <p className="font-heading text-xl font-semibold text-foreground">Options non disponibles</p>
        <p className="text-sm text-muted-foreground">
          Ce jeu n&apos;a pas encore de fichier .apworld configuré.
        </p>
        <Link className="text-sm text-accent-text hover:text-accent-text-hover" href={backHref}>
          Retour au récapitulatif
        </Link>
      </div>
    );
  }

  return (
    <article className="mx-auto max-w-3xl grid gap-8">
      <header className="grid gap-5">
        <Link
          className="inline-flex w-fit items-center gap-1.5 rounded border border-border bg-background px-3 py-2 text-sm font-medium text-foreground transition-colors hover:border-accent"
          href={backHref}
        >
          <ArrowLeft aria-hidden="true" className="size-4" />
          Retour au récapitulatif
        </Link>

        <div className="grid gap-1.5">
          <p className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
            {data.eventTitle}
          </p>
          <h1 className="font-heading text-2xl font-bold leading-tight text-foreground">
            {data.slotLabel}
          </h1>
          <p className="text-sm text-muted-foreground">Configuration du randomizer</p>
        </div>

        {!data.registrationOpen ? (
          <div className="flex items-center gap-3 rounded-lg border border-border bg-surface px-4 py-3">
            <Lock aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
            <p className="text-sm text-muted-foreground">
              La période d&apos;inscription est terminée. Les options sont en lecture seule.
            </p>
          </div>
        ) : null}
      </header>

      <YamlOptionEditor
        defaultYaml={data.game.defaultYaml}
        optionTypes={data.game.optionTypes}
        playerYaml={data.slot.playerYaml}
        registrationId={registrationId}
        registrationOpen={data.registrationOpen}
        slotId={slotId}
        onDirty={() => undefined}
        onSaved={() => undefined}
      />

      <Link
        className="inline-flex min-h-11 w-full items-center justify-center gap-1.5 rounded border border-border bg-background px-3 text-sm font-medium text-foreground transition-colors hover:border-accent sm:w-fit sm:justify-start"
        href={backHref}
      >
        <ArrowLeft aria-hidden="true" className="size-4" />
        Retour au récapitulatif
      </Link>
    </article>
  );
}
