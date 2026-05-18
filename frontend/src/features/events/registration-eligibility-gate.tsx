"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { use, useEffect, useState } from "react";
import { AlertCircle, CalendarClock, CheckCircle, Lock, Users, XCircle } from "lucide-react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { SeatCounter } from "./seat-counter";

type EligibilityReason =
  | "private_event"
  | "event_completed"
  | "event_in_progress"
  | "registration_not_open_yet"
  | "registration_closed"
  | "capacity_full";

type EligibilityEvent = {
  id: string;
  title: string;
  startsAt: string;
  endsAt: string;
  venue: string;
  capacity: number;
  confirmedRegistrations: number;
  registrationOpensAt: string;
  registrationClosesAt: string;
  isPublic: boolean;
};

type EligibilityResult = {
  eligible: boolean;
  reason: EligibilityReason | null;
  opensAt: string | null;
  event: EligibilityEvent;
};

type GateState =
  | { kind: "loading" }
  | { kind: "eligible"; result: EligibilityResult }
  | { kind: "ineligible"; result: EligibilityResult }
  | { kind: "not_found" }
  | { kind: "error"; message: string };

export function RegistrationEligibilityGate({
  params,
}: {
  params: Promise<{ eventSlug: string }>;
}) {
  const { eventSlug } = use(params);
  const router = useRouter();
  const [gateState, setGateState] = useState<GateState>({ kind: "loading" });
  const [seatCounterDisconnected, setSeatCounterDisconnected] = useState(false);

  useEffect(() => {
    let cancelled = false;

    async function run() {
      const profileRes = await apiFetch(`${env.apiBaseUrl}/account/profile`);

      if (cancelled) return;

      if (profileRes.status === 401 || profileRes.status === 403) {
        router.push(`/connexion?returnTo=/evenements/${eventSlug}/inscription`);
        return;
      }

      const eligibilityRes = await fetch(
        `${env.apiBaseUrl}/events/${eventSlug}/registration-eligibility`,
        { credentials: "include" },
      );

      if (cancelled) return;

      if (eligibilityRes.status === 404) {
        setGateState({ kind: "not_found" });
        return;
      }

      if (!eligibilityRes.ok) {
        setGateState({ kind: "error", message: "Impossible de vérifier l'éligibilité." });
        return;
      }

      const payload: unknown = await eligibilityRes.json();
      if (!isEligibilityPayload(payload)) {
        setGateState({ kind: "error", message: "Réponse API invalide." });
        return;
      }

      const result = payload.data;
      setSeatCounterDisconnected(false);
      setGateState(result.eligible ? { kind: "eligible", result } : { kind: "ineligible", result });
    }

    void run().catch(() => {
      if (!cancelled) {
        setGateState({ kind: "error", message: "Impossible de contacter l'API." });
      }
    });

    return () => {
      cancelled = true;
    };
  }, [eventSlug, router]);

  // Poll eligibility every 30s to refresh seat count and detect capacity changes.
  useEffect(() => {
    const intervalId = setInterval(() => {
      void fetch(`${env.apiBaseUrl}/events/${eventSlug}/registration-eligibility`, {
        credentials: "include",
      })
        .then(async (res) => {
          if (!res.ok) {
            setSeatCounterDisconnected(true);
            return;
          }
          const payload: unknown = await res.json();
          if (!isEligibilityPayload(payload)) {
            setSeatCounterDisconnected(true);
            return;
          }
          const fresh = payload.data;
          setSeatCounterDisconnected(false);
          setGateState((prev) => {
            if (prev.kind !== "eligible" && prev.kind !== "ineligible") return prev;
            const updatedResult = { ...prev.result, event: fresh.event };
            if (!fresh.eligible && fresh.reason === "capacity_full") {
              return { kind: "ineligible", result: { ...updatedResult, eligible: false, reason: "capacity_full" } };
            }
            return { ...prev, result: updatedResult };
          });
        })
        .catch(() => setSeatCounterDisconnected(true));
    }, 30_000);

    return () => clearInterval(intervalId);
  }, [eventSlug]);

  if (gateState.kind === "loading") {
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

  if (gateState.kind === "not_found") {
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

  if (gateState.kind === "error") {
    return (
      <div className="grid gap-4 card-glow rounded-lg border border-border p-8 text-center">
        <AlertCircle aria-hidden="true" className="mx-auto size-8 text-danger" />
        <p className="font-heading text-xl font-semibold text-foreground">Erreur</p>
        <p className="text-sm text-muted-foreground">{gateState.message}</p>
      </div>
    );
  }

  const { result } = gateState;

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

      {gateState.kind === "eligible" ? (
        <EligiblePanel eventSlug={eventSlug} />
      ) : result.reason === "private_event" ? (
        <>
          <IneligiblePanel reason={result.reason} opensAt={result.opensAt} />
          <PrivateAccessDisclosure
            eventSlug={eventSlug}
            onGranted={() =>
              setGateState({ kind: "eligible", result: { ...result, eligible: true, reason: null } })
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

function isEligibilityPayload(payload: unknown): payload is { data: EligibilityResult } {
  const data =
    payload && typeof payload === "object" && "data" in payload
      ? (payload as { data: unknown }).data
      : null;

  return Boolean(
    data &&
      typeof data === "object" &&
      "eligible" in data &&
      "event" in data,
  );
}
