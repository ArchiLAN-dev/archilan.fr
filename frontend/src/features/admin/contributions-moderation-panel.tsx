"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader2 } from "lucide-react";

import { InstallStepsView } from "@/features/games/install-steps-view";
import {
  approveContribution,
  fetchContributionQueue,
  rejectContribution,
  type ContributionItem,
} from "./admin-game-contributions-api";

const QUERY_KEY = ["admin-game-contributions"] as const;

export function ContributionsModerationPanel() {
  const queryClient = useQueryClient();
  const { data, isLoading, isError } = useQuery({ queryKey: QUERY_KEY, queryFn: fetchContributionQueue, staleTime: 15_000 });
  const [busyId, setBusyId] = useState<string | null>(null);

  async function run(id: string, action: () => Promise<boolean>): Promise<void> {
    setBusyId(id);
    await action();
    await queryClient.invalidateQueries({ queryKey: QUERY_KEY });
    setBusyId(null);
  }

  if (isLoading) {
    return (
      <p className="flex items-center gap-2 text-sm text-muted-foreground">
        <Loader2 aria-hidden="true" className="size-4 animate-spin" /> Chargement…
      </p>
    );
  }

  if (isError || !data) {
    return <p className="text-sm text-muted-foreground">Impossible de charger les contributions.</p>;
  }

  if (data.length === 0) {
    return (
      <p className="rounded-lg border border-border bg-surface px-4 py-8 text-center text-sm text-muted-foreground">
        Aucune contribution en attente. 🎉
      </p>
    );
  }

  return (
    <ul className="grid gap-4" role="list">
      {data.map((item) => (
        <li key={item.id}>
          <ContributionCard
            busy={busyId === item.id}
            item={item}
            onApprove={() => void run(item.id, () => approveContribution(item.id))}
            onReject={(reason) => void run(item.id, () => rejectContribution(item.id, reason))}
          />
        </li>
      ))}
    </ul>
  );
}

function ContributionCard({
  item,
  busy,
  onApprove,
  onReject,
}: {
  item: ContributionItem;
  busy: boolean;
  onApprove: () => void;
  onReject: (reason: string) => void;
}) {
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState("");

  return (
    <article className="grid gap-4 rounded-lg border border-border bg-surface p-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 className="font-heading font-semibold text-foreground">{item.target}</h3>
          <p className="text-xs text-muted-foreground">
            par {item.authorName || "inconnu"} · {new Date(item.createdAt).toLocaleString("fr-FR")}
          </p>
        </div>
        {item.gameSlug === null ? (
          <span className="rounded border border-warning/50 bg-warning/10 px-2 py-0.5 text-xs font-semibold text-warning">
            Jeu non listé
          </span>
        ) : null}
      </div>

      {item.message ? (
        <p className="whitespace-pre-line rounded border border-border bg-background px-3 py-2 text-sm text-muted-foreground">
          {item.message}
        </p>
      ) : null}

      <div className="grid gap-4 lg:grid-cols-2">
        {item.gameSlug !== null ? (
          <div className="grid gap-2">
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Actuel</p>
            {item.currentSteps.length > 0 ? (
              <InstallStepsView steps={item.currentSteps} />
            ) : (
              <p className="text-sm text-muted-foreground">Aucune étape actuelle.</p>
            )}
          </div>
        ) : null}
        <div className="grid gap-2">
          <p className="text-xs font-semibold uppercase tracking-wide text-accent-text">Proposé</p>
          <InstallStepsView steps={item.proposedSteps} />
        </div>
      </div>

      <p className="text-xs text-muted-foreground">
        Approuver <strong className="text-foreground">remplace l&apos;intégralité</strong> du tutoriel par la
        version proposée.
      </p>

      {rejecting ? (
        <div className="grid gap-2">
          <textarea
            aria-label="Raison du refus"
            className="min-h-16 w-full rounded border border-border bg-background px-3 py-2 text-sm outline-none focus:border-accent"
            onChange={(e) => setReason(e.target.value)}
            placeholder="Raison du refus (envoyée à l'auteur)"
            value={reason}
          />
          <div className="flex flex-wrap items-center gap-3">
            <button
              className="inline-flex min-h-9 items-center justify-center rounded bg-danger px-4 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:opacity-50"
              disabled={busy || reason.trim() === ""}
              onClick={() => onReject(reason)}
              type="button"
            >
              Confirmer le refus
            </button>
            <button className="text-sm text-muted-foreground hover:text-foreground" onClick={() => setRejecting(false)} type="button">
              Annuler
            </button>
          </div>
        </div>
      ) : (
        <div className="flex flex-wrap items-center gap-3">
          <button
            className="inline-flex min-h-9 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
            disabled={busy}
            onClick={onApprove}
            type="button"
          >
            {busy ? "…" : "Approuver"}
          </button>
          <button
            className="inline-flex min-h-9 items-center justify-center rounded border border-border px-4 text-sm font-semibold text-foreground transition-colors hover:border-danger disabled:opacity-50"
            disabled={busy}
            onClick={() => setRejecting(true)}
            type="button"
          >
            Rejeter
          </button>
        </div>
      )}
    </article>
  );
}
