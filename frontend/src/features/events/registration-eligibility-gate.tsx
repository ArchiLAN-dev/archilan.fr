"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { use, useEffect, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { AlertCircle, CalendarClock, CheckCircle, Lock, Users, XCircle } from "lucide-react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import {
  fetchAuthProbe,
  fetchRegistrationEligibility,
  type EligibilityReason,
  type EligibilityResult,
  type RegistrationEligibilityResult,
} from "./events-api";
import { SeatCounter } from "./seat-counter";

// "error" results are thrown inside the queryFn so TanStack keeps the last good data when a
// 30 s background poll fails (the seat counter must not blank out on a transient failure).
type EligibilityQueryData = Exclude<RegistrationEligibilityResult, { kind: "error" }>;

export function RegistrationEligibilityGate({
  params,
}: {
  params: Promise<{ eventSlug: string }>;
}) {
  const { eventSlug } = use(params);
  const router = useRouter();
  const queryClient = useQueryClient();

  // Fresh session probe on every gate entry (staleTime 0), like the pre-TanStack effect.
  const authQuery = useQuery({
    queryKey: ["registration-auth-probe"],
    queryFn: fetchAuthProbe,
    staleTime: 0,
    retry: false,
  });

  useEffect(() => {
    if (authQuery.data === "unauthenticated") {
      router.push(`/connexion?returnTo=/evenements/${eventSlug}/inscription`);
    }
  }, [authQuery.data, eventSlug, router]);

  const eligibilityQuery = useQuery({
    queryKey: ["registration-eligibility", eventSlug],
    queryFn: async (): Promise<EligibilityQueryData> => {
      const fresh = await fetchRegistrationEligibility(eventSlug);
      if (fresh.kind === "error") throw new Error(fresh.message);
      if (fresh.kind === "not_found") return fresh;

      // 30 s poll semantics preserved from the pre-TanStack interval: once the gate decision
      // is made, a refetch only updates the seat counts in place - it never flips the
      // eligible/ineligible decision, except when capacity fills up.
      const prev = queryClient.getQueryData<EligibilityQueryData>([
        "registration-eligibility",
        eventSlug,
      ]);
      if (!prev || prev.kind !== "success") return fresh;

      const updatedResult: EligibilityResult = { ...prev.result, event: fresh.result.event };
      if (!fresh.result.eligible && fresh.result.reason === "capacity_full") {
        return {
          kind: "success",
          result: { ...updatedResult, eligible: false, reason: "capacity_full" },
        };
      }
      return { kind: "success", result: updatedResult };
    },
    enabled: authQuery.data === "authenticated",
    // Seat counts must be fresh on every mount (the pre-TanStack gate always refetched).
    staleTime: 0,
    // Poll eligibility every 30s to refresh seat count and detect capacity changes.
    refetchInterval: 30_000,
    retry: false,
  });

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

  const gateResult = eligibilityQuery.data;

  if (gateResult === undefined) {
    if (eligibilityQuery.isError) {
      const message =
        eligibilityQuery.error instanceof Error
          ? eligibilityQuery.error.message
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
        {/* header */}
        <div className="grid gap-3">
          <div className="h-3 w-20 animate-pulse rounded bg-surface-2" />
          <div className="h-10 w-72 animate-pulse rounded bg-surface-2" />
          <div className="h-4 w-48 animate-pulse rounded bg-surface-2" />
        </div>
        {/* seat counter */}
        <div className="h-16 w-full animate-pulse rounded-lg border border-border bg-surface-2" />
        {/* eligibility panel */}
        <div className="grid gap-4 rounded-lg border border-border p-6">
          <div className="h-5 w-40 animate-pulse rounded bg-surface-2" />
          <div className="h-4 w-64 animate-pulse rounded bg-surface-2" />
          <div className="h-11 w-44 animate-pulse rounded bg-surface-2" />
        </div>
        {/* back link */}
        <div className="h-4 w-36 animate-pulse rounded bg-surface-2" />
      </div>
    );
  }

  if (gateResult.kind === "not_found") {
    return (
      <div className="grid gap-4 card-glow rounded-lg border border-border p-8 text-center">
        <XCircle aria-hidden="true" className="mx-auto size-8 text-danger" />
        <p className="font-heading text-xl font-semibold text-foreground">Événement introuvable</p>
        <p className="text-sm text-muted-foreground">Cet événement n&apos;existe pas ou n&apos;est plus accessible.</p>
        <Link className="text-sm text-accent-text hover:text-accent-text-hover" href="/evenements">
          Voir tous les événements
        </Link>
      </div>
    );
  }

  const { result } = gateResult;
  // A failed background poll keeps the last data (queryFn throws on error) and only flags
  // the seat counter as disconnected, like the pre-TanStack interval.
  const seatCounterDisconnected = eligibilityQuery.isError;

  return (
    <article className="grid gap-8">
      <header className="grid gap-2">
        <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">
          {result.event.title}
        </h1>
        <p className="text-sm text-muted-foreground">
          {result.event.venue} · <time dateTime={result.event.startsAt}>{formatDate(result.event.startsAt)}</time>
        </p>
      </header>

      <SeatCounter
        capacity={result.event.capacity}
        confirmedRegistrations={result.event.confirmedRegistrations}
        loading={seatCounterDisconnected}
      />

      {result.eligible ? (
        <EligiblePanel eventSlug={eventSlug} />
      ) : result.reason === "private_event" ? (
        <>
          <IneligiblePanel reason={result.reason} opensAt={result.opensAt} />
          <PrivateAccessDisclosure
            eventSlug={eventSlug}
            onGranted={() =>
              queryClient.setQueryData<EligibilityQueryData>(
                ["registration-eligibility", eventSlug],
                (prev) =>
                  prev?.kind === "success"
                    ? { kind: "success", result: { ...prev.result, eligible: true, reason: null } }
                    : prev,
              )
            }
          />
        </>
      ) : (
        <IneligiblePanel reason={result.reason} opensAt={result.opensAt} />
      )}

      <Link className="text-sm text-accent-text hover:text-accent-text-hover" href={`/evenements/${eventSlug}`}>
        Retour à la page de l&apos;événement
      </Link>
    </article>
  );
}

type ReserveState =
  | { kind: "idle" }
  | { kind: "loading" }
  | { kind: "reserved"; registrationId: string }
  | { kind: "already_registered"; registrationId: string }
  | { kind: "capacity_full" }
  | { kind: "error" };

function EligiblePanel({ eventSlug }: { eventSlug: string }) {
  const queryClient = useQueryClient();
  const [state, setState] = useState<ReserveState>({ kind: "idle" });

  if (state.kind === "capacity_full") {
    return (
      <div className="grid gap-4 rounded-lg border border-danger/40 bg-surface/40 backdrop-blur-md p-6">
        <div className="flex items-center gap-3">
          <XCircle aria-hidden="true" className="size-5 text-danger" />
          <p className="font-heading text-xl font-semibold text-foreground">Événement complet</p>
        </div>
        <p className="text-sm leading-6 text-muted-foreground">
          Toutes les places viennent d&apos;être réservées. Surveille les annonces ArchiLAN pour les prochaines sessions.
        </p>
        <Link
          className="text-sm text-accent-text hover:text-accent-text-hover"
          href={`/evenements/${eventSlug}`}
        >
          Retour à l&apos;événement
        </Link>
      </div>
    );
  }

  if (state.kind === "reserved" || state.kind === "already_registered") {
    return (
      <div className="grid gap-4 rounded-lg border border-success/40 bg-surface/40 backdrop-blur-md p-6">
        <div className="flex items-center gap-3">
          <CheckCircle aria-hidden="true" className="size-5 text-success" />
          <p className="font-heading text-xl font-semibold text-foreground">Place réservée</p>
        </div>
        <p className="text-sm leading-6 text-muted-foreground">
          Ta place est confirmée. Tu peux maintenant sélectionner tes jeux Archipelago pour cet événement.
        </p>
        <p className="text-xs text-muted-foreground">
          Réf. inscription&nbsp;: <code className="font-mono">{state.registrationId}</code>
        </p>
        <Link
          className="inline-flex min-h-12 w-fit items-center justify-center rounded bg-accent px-6 font-semibold text-white transition-colors hover:bg-accent-hover"
          href={`/evenements/${eventSlug}/inscription/${state.registrationId}/jeux`}
        >
          Choisir mes jeux →
        </Link>
      </div>
    );
  }

  async function handleReserve() {
    setState({ kind: "loading" });

    try {
      const res = await apiFetch(`${env.apiBaseUrl}/events/${eventSlug}/registrations`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
      });

      if (res.status === 409) {
        setState({ kind: "capacity_full" });
        return;
      }


      if (!res.ok) {
        setState({ kind: "error" });
        return;
      }

      const payload: unknown = await res.json();
      const data = payload && typeof payload === "object" && "data" in payload
        ? (payload as { data: unknown }).data
        : null;

      if (
        data &&
        typeof data === "object" &&
        "registrationId" in data &&
        typeof (data as { registrationId?: unknown }).registrationId === "string"
      ) {
        const registrationId = (data as { outcome: string; registrationId: string }).registrationId;
        const outcome = (data as { outcome: string; registrationId: string }).outcome;
        setState(
          outcome === "already_registered"
            ? { kind: "already_registered", registrationId }
            : { kind: "reserved", registrationId },
        );
        // The event-page CTA caches my-registration: a fresh reservation must invalidate it.
        void queryClient.invalidateQueries({ queryKey: ["event-my-registration"] });
      } else {
        setState({ kind: "error" });
      }
    } catch {
      setState({ kind: "error" });
    }
  }

  return (
    <div className="grid gap-5 rounded-lg border border-success/40 bg-surface/40 backdrop-blur-md p-6">
      <div className="flex items-center gap-3">
        <CheckCircle aria-hidden="true" className="size-5 text-success" />
        <p className="font-heading text-xl font-semibold text-foreground">Inscription disponible</p>
      </div>
      <p className="text-sm leading-6 text-muted-foreground">
        Tu peux commencer ton inscription pour cet événement. Tu choisiras tes jeux et configureras tes options Archipelago dans les étapes suivantes.
      </p>
      {state.kind === "error" ? (
        <p className="text-sm text-danger">Une erreur est survenue. Réessaie dans quelques instants.</p>
      ) : null}
      <button
        className="inline-flex min-h-12 w-fit items-center justify-center rounded bg-accent px-6 font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
        disabled={state.kind === "loading"}
        onClick={() => { void handleReserve(); }}
        type="button"
      >
        {state.kind === "loading" ? "Réservation…" : "Commencer l’inscription"}
      </button>
    </div>
  );
}

function IneligiblePanel({
  reason,
  opensAt,
}: {
  reason: EligibilityReason | null;
  opensAt: string | null;
}) {
  const info = ineligibleInfo(reason, opensAt);

  return (
    <div className="grid gap-4 card-glow rounded-lg border border-border p-6">
      <div className="flex items-center gap-3">
        {info.icon}
        <p className="font-heading text-xl font-semibold text-foreground">{info.title}</p>
      </div>
      <p className="text-sm leading-6 text-muted-foreground">{info.description}</p>
    </div>
  );
}

function ineligibleInfo(
  reason: EligibilityReason | null,
  opensAt: string | null,
): { icon: React.ReactNode; title: string; description: string } {
  switch (reason) {
    case "private_event":
      return {
        icon: <Lock aria-hidden="true" className="size-5 text-muted-foreground" />,
        title: "Événement privé",
        description: "Cet événement est réservé aux membres ArchiLAN. Si tu as un code d'accès, utilise-le ci-dessous.",
      };
    case "event_completed":
      return {
        icon: <XCircle aria-hidden="true" className="size-5 text-muted-foreground" />,
        title: "Événement terminé",
        description: "Cet événement est terminé. Consulte les prochaines sessions sur la page des événements.",
      };
    case "event_in_progress":
      return {
        icon: <XCircle aria-hidden="true" className="size-5 text-muted-foreground" />,
        title: "Événement en cours",
        description: "Cet événement a déjà commencé. Les inscriptions ne sont plus disponibles.",
      };
    case "registration_not_open_yet":
      return {
        icon: <CalendarClock aria-hidden="true" className="size-5 text-accent-text" />,
        title: "Inscriptions pas encore ouvertes",
        description: opensAt
          ? `Les inscriptions ouvrent le ${formatDate(opensAt)}. Reviens à cette date pour t'inscrire.`
          : "Les inscriptions ne sont pas encore ouvertes pour cet événement.",
      };
    case "registration_closed":
      return {
        icon: <XCircle aria-hidden="true" className="size-5 text-danger" />,
        title: "Inscriptions fermées",
        description: "La période d'inscription pour cet événement est terminée.",
      };
    case "capacity_full":
      return {
        icon: <Users aria-hidden="true" className="size-5 text-danger" />,
        title: "Complet",
        description: "Toutes les places sont réservées. Surveille les annonces ArchiLAN pour les prochaines sessions.",
      };
    default:
      return {
        icon: <XCircle aria-hidden="true" className="size-5 text-danger" />,
        title: "Inscription indisponible",
        description: "L'inscription n'est pas disponible pour cet événement actuellement.",
      };
  }
}

function PrivateAccessDisclosure({
  eventSlug,
  onGranted,
}: {
  eventSlug: string;
  onGranted: () => void;
}) {
  const [status, setStatus] = useState<"idle" | "loading" | "error">("idle");
  const [password, setPassword] = useState("");

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!password || status === "loading") return;
    setStatus("loading");

    try {
      const res = await apiFetch(`${env.apiBaseUrl}/events/${eventSlug}/verify-private-access`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ password }),
      });

      if (!res.ok) {
        setStatus("error");
        return;
      }

      const payload: unknown = await res.json();
      if (isGrantedResponse(payload)) {
        onGranted();
      } else {
        setStatus("error");
      }
    } catch {
      setStatus("error");
    }
  }

  return (
    <details className="card-glow rounded-lg border border-border">
      <summary className="cursor-pointer select-none px-5 py-4 text-sm font-semibold text-foreground hover:text-accent-text">
        J&apos;ai un code d&apos;accès
      </summary>
      <div className="border-t border-border p-5">
        <form className="grid gap-4" onSubmit={(e) => { void handleSubmit(e); }}>
          <div className="grid gap-1.5">
            <label className="text-sm font-medium text-foreground" htmlFor="private-access-password">
              Code d&apos;accès
            </label>
            <input
              autoComplete="off"
              className="h-10 rounded border border-border bg-background px-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-accent"
              disabled={status === "loading"}
              id="private-access-password"
              onChange={(e) => {
                setPassword(e.target.value);
                if (status === "error") setStatus("idle");
              }}
              type="password"
              value={password}
            />
            {status === "error" ? (
              <p className="text-xs text-danger">Code d&apos;accès invalide.</p>
            ) : null}
          </div>
          <button
            className="inline-flex min-h-10 w-fit items-center justify-center rounded bg-accent px-5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
            disabled={status === "loading" || !password}
            type="submit"
          >
            {status === "loading" ? "Vérification…" : "Accéder"}
          </button>
        </form>
      </div>
    </details>
  );
}

function formatDate(value: string) {
  return new Intl.DateTimeFormat("fr-FR", {
    dateStyle: "long",
  }).format(new Date(value));
}

function isGrantedResponse(payload: unknown): boolean {
  if (!payload || typeof payload !== "object") return false;
  const data = (payload as { data?: unknown }).data;
  return (
    !!data &&
    typeof data === "object" &&
    "granted" in (data as Record<string, unknown>) &&
    (data as { granted?: unknown }).granted === true
  );
}
