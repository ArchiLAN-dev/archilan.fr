"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { DoorOpen, Loader2, Undo2, Unlock } from "lucide-react";

import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { decideDeparture, departuresKey, fetchDepartures, fetchMyDeparture, myDepartureKey, sayILeave, type Departure } from "./departures-api";

const WHEN = new Intl.DateTimeFormat("fr-FR", { weekday: "short", hour: "2-digit", minute: "2-digit", timeZone: "Europe/Paris" });

/**
 * « Je pars » (story 52.6): the player tells the staff (the owner of a private run) they leave, so their world can be
 * released and nobody stays blocked by them. They never release it themselves.
 */
export function LeaveButton({ sessionId, slotIndex }: { sessionId: string; slotIndex: string }) {
  const queryClient = useQueryClient();
  const { data } = useQuery({
    queryKey: myDepartureKey(sessionId, slotIndex),
    queryFn: () => fetchMyDeparture(sessionId, slotIndex),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (!data?.available) return null;

  return (
    <LeaveView
      onLeave={async () => {
        const error = await sayILeave(sessionId, slotIndex);
        await queryClient.invalidateQueries({ queryKey: myDepartureKey(sessionId, slotIndex) });
        return error;
      }}
      requestedAt={data.requestedAt}
    />
  );
}

export function LeaveView({ requestedAt, onLeave }: { requestedAt: string | null; onLeave: () => Promise<string | null> }) {
  const [confirming, setConfirming] = useState(false);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function leave(): Promise<void> {
    setPending(true);
    setError(await onLeave());
    setPending(false);
    setConfirming(false);
  }

  if (requestedAt !== null) {
    return (
      <p className="mb-4 flex items-center gap-2 text-xs text-muted-foreground">
        <DoorOpen aria-hidden className="size-3.5" />
        Départ signalé à {WHEN.format(new Date(requestedAt))} : l&apos;orga libérera ton monde.
      </p>
    );
  }

  return (
    <div className="mb-4 flex flex-wrap items-center gap-2">
      <button
        className="inline-flex min-h-8 items-center gap-1.5 rounded border border-border px-2.5 text-xs font-semibold text-foreground hover:border-accent disabled:opacity-40"
        disabled={pending}
        onClick={() => setConfirming(true)}
        type="button"
      >
        <DoorOpen aria-hidden className="size-3.5" />
        Je pars
      </button>
      {error !== null ? <p className="text-xs text-danger">{error}</p> : null}
      <ConfirmDialog
        confirmLabel="Prévenir l'orga"
        description="L'orga est prévenue que tu quittes la partie et pourra libérer ton monde, pour que personne ne reste bloqué par toi. Rien n'est libéré tant qu'elle ne l'a pas fait."
        icon={DoorOpen}
        onConfirm={() => void leave()}
        onOpenChange={setConfirming}
        open={confirming}
        pending={pending}
        title="Tu pars ?"
      />
    </div>
  );
}

/**
 * « Départs à traiter » (story 52.6): the players who left, for the staff of an event or the owner of a private run.
 * Release their world in one click (through the bridge, announced in the feed), or dismiss (« il revient »).
 * Hidden for anyone else, and when there is nothing to handle.
 */
export function DeparturesPanel({ sessionId }: { sessionId: string }) {
  const queryClient = useQueryClient();
  const { data } = useQuery({
    queryKey: departuresKey(sessionId),
    queryFn: () => fetchDepartures(sessionId),
    staleTime: DEFAULT_STALE_TIME,
    refetchInterval: 60_000,
    retry: false,
  });

  if (!data || data.length === 0) return null;

  return (
    <DeparturesView
      departures={data}
      onDecide={async (departure, decision) => {
        const error = await decideDeparture(sessionId, departure.id, decision);
        await queryClient.invalidateQueries({ queryKey: departuresKey(sessionId) });
        return error;
      }}
    />
  );
}

type Decision = { departure: Departure; decision: "release" | "dismiss" };

export function DeparturesView({ departures, onDecide }: { departures: Departure[]; onDecide: (departure: Departure, decision: "release" | "dismiss") => Promise<string | null> }) {
  const [confirming, setConfirming] = useState<Decision | null>(null);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function decide(): Promise<void> {
    if (confirming === null) return;
    setPending(true);
    setError(await onDecide(confirming.departure, confirming.decision));
    setPending(false);
    setConfirming(null);
  }

  const releasing = confirming?.decision === "release";

  return (
    <section aria-labelledby="departures-title" className="mb-4 grid gap-3 rounded border border-accent-warm/40 bg-surface p-4">
      <h2 className="flex items-center gap-2 font-heading text-sm font-semibold text-foreground" id="departures-title">
        <DoorOpen aria-hidden className="size-4 text-accent-warm" />
        Départs à traiter
      </h2>
      <ul className="divide-y divide-border">
        {departures.map((departure) => (
          <li className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm" key={departure.id}>
            <span className="min-w-0">
              <span className="font-medium text-foreground">{departure.slotName}</span>
              <span className="text-xs text-muted-foreground">
                {" "}
                · {departure.origin === "player" ? "a signalé son départ" : "départ déclaré passé, déconnecté"} ({WHEN.format(new Date(departure.createdAt))})
              </span>
            </span>
            <span className="flex shrink-0 items-center gap-2">
              <button
                className="inline-flex min-h-8 items-center gap-1.5 rounded border border-accent-warm/50 px-2.5 text-xs font-semibold text-foreground hover:border-accent-warm disabled:opacity-40"
                disabled={pending}
                onClick={() => setConfirming({ departure, decision: "release" })}
                type="button"
              >
                {pending && releasing && confirming.departure.id === departure.id ? <Loader2 aria-hidden className="size-3 animate-spin" /> : <Unlock aria-hidden className="size-3" />}
                Libérer son monde
              </button>
              <button
                className="inline-flex min-h-8 items-center gap-1.5 rounded border border-border px-2.5 text-xs text-muted-foreground hover:text-foreground disabled:opacity-40"
                disabled={pending}
                onClick={() => setConfirming({ departure, decision: "dismiss" })}
                type="button"
              >
                <Undo2 aria-hidden className="size-3" />
                Il revient
              </button>
            </span>
          </li>
        ))}
      </ul>
      {error !== null ? <p className="text-xs text-danger">{error}</p> : null}
      <ConfirmDialog
        confirmLabel={releasing ? "Libérer" : "Écarter"}
        description={
          releasing
            ? `Tout ce qui reste dans le monde de ${confirming?.departure.slotName ?? ""} est envoyé aux autres joueurs (/release). Ce n'est pas réversible.`
            : `${confirming?.departure.slotName ?? ""} revient : le départ est écarté, rien n'est libéré.`
        }
        icon={releasing ? Unlock : Undo2}
        onConfirm={() => void decide()}
        onOpenChange={(open) => {
          if (!open) setConfirming(null);
        }}
        open={confirming !== null}
        pending={pending}
        title={releasing ? "Libérer son monde ?" : "Il revient ?"}
        tone={releasing ? "danger" : "default"}
      />
    </section>
  );
}
