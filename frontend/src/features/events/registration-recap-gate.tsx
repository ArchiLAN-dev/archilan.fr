"use client";

import Link from "next/link";
import { use, useEffect, useState } from "react";
import { AlertCircle, CheckCircle, Settings, XCircle } from "lucide-react";

import { RegistrationStepper } from "@/features/events/registration-stepper";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

// ─── Types ───────────────────────────────────────────────────────────────────

type RecapSlot = {
  slotId: string;
  slotOrder: number;
  gameId: string;
  gameName: string;
  playerYaml: string | null;
  apworldHash: string | null;
};

type RecapGame = {
  id: string;
  isApworldReady: boolean;
  defaultYaml: string | null;
};

type RecapData = {
  registrationId: string;
  eventTitle: string;
  gameSelectionEnabled: boolean;
  registrationOpen: boolean;
  slots: RecapSlot[];
  gameMap: Map<string, RecapGame>;
};

type GateState =
  | { kind: "loading" }
  | { kind: "data"; data: RecapData }
  | { kind: "not_found" }
  | { kind: "error"; message: string };

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
  const { eventSlug, registrationId } = use(params);
  const [gateState, setGateState] = useState<GateState>({ kind: "loading" });
  const [submitState, setSubmitState] = useState<SubmitState>({ kind: "idle" });

  useEffect(() => {
    let cancelled = false;

    async function run() {
      const profileRes = await apiFetch(`${env.apiBaseUrl}/account/profile`);

      if (cancelled) return;

      if (profileRes.status === 401 || profileRes.status === 403) {
        window.location.href = `/connexion?returnTo=/evenements/${eventSlug}/inscription/${registrationId}/recap`;
        return;
      }

      const res = await apiFetch(`${env.apiBaseUrl}/registrations/${registrationId}/game-selection`);

      if (cancelled) return;

      if (res.status === 404) {
        setGateState({ kind: "not_found" });
        return;
      }

      if (!res.ok) {
        setGateState({ kind: "error", message: "Impossible de charger le récapitulatif." });
        return;
      }

      const payload: unknown = await res.json();
      const data = parseRecapData(payload);

      if (!data) {
        setGateState({ kind: "error", message: "Réponse API invalide." });
        return;
      }

      setGateState({ kind: "data", data });
    }

    void run().catch(() => {
      if (!cancelled) {
        setGateState({ kind: "error", message: "Impossible de contacter l'API." });
      }
    });

    return () => {
      cancelled = true;
    };
  }, [registrationId, eventSlug]);

  async function handleSubmit() {
    if (gateState.kind !== "data") return;
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
        eventTitle: body.data?.eventTitle ?? gateState.data.eventTitle,
        slots: gateState.data.slots,
      });
    } catch {
      setSubmitState({ kind: "error", message: "Impossible de contacter l'API." });
    }
  }

  if (gateState.kind === "loading") {
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

  if (gateState.kind === "not_found") {
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

  if (gateState.kind === "error") {
    return (
      <div className="grid gap-4 card-glow rounded-lg border border-border p-8 text-center">
        <AlertCircle aria-hidden="true" className="mx-auto size-8 text-danger" />
        <p className="font-heading text-xl font-semibold text-foreground">Erreur</p>
        <p className="text-sm text-muted-foreground">{gateState.message}</p>
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

  const { data } = gateState;

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
      <RegistrationStepper currentStep={2} />

      <header className="grid gap-2">
        <h1 className="font-heading text-3xl font-bold leading-tight text-foreground">
          Récapitulatif
        </h1>
        <p className="text-sm text-muted-foreground">{data.eventTitle}</p>
      </header>

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
                const yamlStatus = resolveYamlStatus(slot, game);
                return (
                  <li
                    key={slot.slotId}
                    className="flex items-center gap-3 bg-background px-4 py-3 first:rounded-t-lg last:rounded-b-lg transition-colors hover:bg-surface"
                  >
                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-semibold leading-tight text-foreground">{label}</p>
                      <div className="mt-1">
                        <YamlStatusBadge status={yamlStatus} />
                      </div>
                    </div>
                    {game?.isApworldReady ? (
                      <Link
                        className="shrink-0 inline-flex items-center gap-1.5 rounded border border-border px-3 py-1.5 text-xs font-semibold text-foreground transition-colors hover:border-accent hover:text-accent-text"
                        href={`/evenements/${eventSlug}/inscription/${registrationId}/slots/${slot.slotId}`}
                      >
                        <Settings aria-hidden="true" className="size-3.5" />
                        Configurer
                      </Link>
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
          {data.registrationOpen ? (
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

// ─── YAML status ──────────────────────────────────────────────────────────────

type YamlStatusKind = "custom" | "default" | "required" | "none";

function resolveYamlStatus(slot: RecapSlot, game: RecapGame | undefined): YamlStatusKind {
  if (!game?.isApworldReady) return "none";
  if (!slot.playerYaml) return game.defaultYaml ? "default" : "required";
  if (slot.playerYaml === game?.defaultYaml) return "default";
  return "custom";
}

function YamlStatusBadge({ status }: { status: YamlStatusKind }) {
  if (status === "none") {
    return <span className="text-xs text-muted-foreground">-</span>;
  }
  if (status === "custom") {
    return (
      <span className="inline-flex items-center gap-1 text-xs font-medium text-success">
        <CheckCircle aria-hidden="true" className="size-3.5" />
        Personnalisé
      </span>
    );
  }
  if (status === "default") {
    return (
      <span className="text-xs font-medium text-muted-foreground">Par défaut</span>
    );
  }
  return (
    <span className="inline-flex items-center gap-1 text-xs font-medium text-accent-warm">
      <AlertCircle aria-hidden="true" className="size-3.5" />
      À configurer
    </span>
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

// ─── Parsers ──────────────────────────────────────────────────────────────────

function parseRecapSlot(x: unknown): RecapSlot | null {
  if (!x || typeof x !== "object") return null;
  const s = x as Record<string, unknown>;
  if (
    typeof s.slotId !== "string" ||
    typeof s.slotOrder !== "number" ||
    typeof s.gameId !== "string" ||
    typeof s.gameName !== "string"
  ) {
    return null;
  }
  return {
    slotId: s.slotId,
    slotOrder: s.slotOrder,
    gameId: s.gameId,
    gameName: s.gameName,
    playerYaml: typeof s.playerYaml === "string" ? s.playerYaml : null,
    apworldHash: typeof s.apworldHash === "string" ? s.apworldHash : null,
  };
}

function parseRecapGame(x: unknown): RecapGame | null {
  if (!x || typeof x !== "object") return null;
  const g = x as Record<string, unknown>;
  if (typeof g.id !== "string") return null;
  return {
    id: g.id,
    isApworldReady: g.isApworldReady === true,
    defaultYaml: typeof g.defaultYaml === "string" ? g.defaultYaml : null,
  };
}

function parseRecapData(payload: unknown): RecapData | null {
  if (!payload || typeof payload !== "object") return null;
  const data = (payload as { data?: unknown }).data;
  if (!data || typeof data !== "object") return null;
  const d = data as Record<string, unknown>;

  if (
    typeof d.registrationId !== "string" ||
    typeof d.eventTitle !== "string" ||
    typeof d.gameSelectionEnabled !== "boolean" ||
    !Array.isArray(d.slots)
  ) {
    return null;
  }

  const slots = (d.slots as unknown[]).flatMap((s) => {
    const parsed = parseRecapSlot(s);
    return parsed ? [parsed] : [];
  });

  const gameMap = new Map<string, RecapGame>();
  if (Array.isArray(d.availableGames)) {
    for (const g of d.availableGames) {
      const game = parseRecapGame(g);
      if (game) gameMap.set(game.id, game);
    }
  }

  return {
    registrationId: d.registrationId,
    eventTitle: d.eventTitle,
    gameSelectionEnabled: d.gameSelectionEnabled,
    registrationOpen: typeof d.registrationOpen === "boolean" ? d.registrationOpen : true,
    slots,
    gameMap,
  };
}
