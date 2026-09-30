"use client";

import Link from "next/link";
import { useState } from "react";
import { AlertTriangle, CheckCircle2, EyeOff, Hand } from "lucide-react";

import { ConfirmDialog } from "@/components/ui/confirm-dialog";

import { INCIDENT_STATUS_LABELS, INCIDENT_TYPE_LABELS, type ApworldIncident } from "./admin-apworld-health-api";
import { formatIncidentDate, incidentOwnerLine } from "./apworld-incident-format";

type Props = {
  incidents: ApworldIncident[];
  emptyMessage: string;
  /** The incident an action is running on: its buttons are disabled until the list refreshes. */
  pendingId: string | null;
  onAcknowledge: (id: string) => void;
  onResolve: (id: string) => void;
  onIgnore: (id: string) => void;
};

const SHORT_HASH_LENGTH = 12;

const BUTTON =
  "inline-flex min-h-9 items-center gap-1.5 rounded-md border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:bg-accent/10 disabled:cursor-not-allowed disabled:opacity-50";

/**
 * The incidents of the apworld health page (story 38.3). Presentational: the page owns the data and the
 * actions, this only says what broke, since when, who holds it, and offers what the lifecycle allows.
 */
export function ApworldIncidentList({ incidents, emptyMessage, pendingId, onAcknowledge, onResolve, onIgnore }: Props) {
  if (incidents.length === 0) {
    return (
      <p className="flex items-center gap-2 rounded-lg border border-border bg-surface px-4 py-6 text-sm text-muted-foreground">
        <CheckCircle2 aria-hidden className="size-4 text-success" />
        {emptyMessage}
      </p>
    );
  }

  return (
    <ul className="grid gap-3">
      {incidents.map((incident) => (
        <li key={incident.id}>
          <IncidentCard
            busy={pendingId === incident.id}
            incident={incident}
            onAcknowledge={onAcknowledge}
            onIgnore={onIgnore}
            onResolve={onResolve}
          />
        </li>
      ))}
    </ul>
  );
}

type CardProps = {
  incident: ApworldIncident;
  busy: boolean;
  onAcknowledge: (id: string) => void;
  onResolve: (id: string) => void;
  onIgnore: (id: string) => void;
};

function IncidentCard({ incident, busy, onAcknowledge, onResolve, onIgnore }: CardProps) {
  const [confirmingIgnore, setConfirmingIgnore] = useState(false);
  const active = incident.status === "open" || incident.status === "acknowledged";

  return (
    <article className={`grid gap-3 rounded-lg border bg-surface p-4 ${active ? "border-danger/40" : "border-border"}`}>
      <header className="flex flex-wrap items-center gap-x-3 gap-y-1">
        {active ? <AlertTriangle aria-hidden className="size-4 text-danger" /> : <CheckCircle2 aria-hidden className="size-4 text-muted-foreground" />}
        <Link className="font-heading text-base font-bold text-foreground hover:underline" href={`/admin/jeux/${incident.gameId}`}>
          {incident.gameName}
        </Link>
        <span className="rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">
          {INCIDENT_TYPE_LABELS[incident.type] ?? incident.type}
        </span>
        <span className="rounded-full border border-border px-2 py-0.5 text-xs font-semibold text-foreground">
          {INCIDENT_STATUS_LABELS[incident.status] ?? incident.status}
        </span>
      </header>

      <p className="text-sm text-foreground">{incident.summary}</p>

      <dl className="grid grid-cols-1 gap-x-6 gap-y-1 text-xs text-muted-foreground sm:grid-cols-2">
        <div>
          <dt className="inline">Ouvert le </dt>
          <dd className="inline">{formatIncidentDate(incident.openedAt)}</dd>
        </div>
        <div>
          <dt className="inline">Dernier constat le </dt>
          <dd className="inline">{formatIncidentDate(incident.lastSeenAt)}</dd>
          {` · ${incident.occurrences} ${incident.occurrences > 1 ? "occurrences" : "occurrence"}`}
        </div>
        <div>
          <dt className="inline">Version </dt>
          <dd className="inline font-mono">{incident.apworldHash.slice(0, SHORT_HASH_LENGTH)}</dd>
        </div>
        <div>
          <dt className="sr-only">Suivi</dt>
          <dd className="font-semibold text-foreground">{incidentOwnerLine(incident)}</dd>
        </div>
      </dl>

      <details className="text-xs">
        <summary className="cursor-pointer text-muted-foreground hover:text-foreground">Erreur complète</summary>
        <pre className="mt-2 max-h-80 overflow-auto whitespace-pre-wrap rounded-md border border-border bg-background p-3 font-mono text-[11px] text-foreground">
          {incident.error}
        </pre>
      </details>

      {active && (
        <div className="flex flex-wrap gap-2">
          <button className={BUTTON} disabled={busy} onClick={() => onAcknowledge(incident.id)} type="button">
            <Hand aria-hidden className="size-4" />
            {incident.status === "acknowledged" ? "Reprendre" : "Je m'en occupe"}
          </button>
          <button className={BUTTON} disabled={busy} onClick={() => onResolve(incident.id)} type="button">
            <CheckCircle2 aria-hidden className="size-4" />
            Résoudre
          </button>
          <button
            aria-haspopup="dialog"
            className={BUTTON}
            disabled={busy}
            onClick={() => setConfirmingIgnore(true)}
            type="button"
          >
            <EyeOff aria-hidden className="size-4" />
            Ignorer
          </button>
        </div>
      )}

      {/* Story 38.13: a modal, not a confirmation unfolded inside the card. */}
      <ConfirmDialog
        confirmLabel="Ignorer quand même"
        description={`Il ne se rouvrira pas tant que ${incident.gameName} sert cette version de l'apworld, même si le test échoue encore. Une nouvelle version sera surveillée normalement.`}
        onConfirm={() => {
          setConfirmingIgnore(false);
          onIgnore(incident.id);
        }}
        onOpenChange={setConfirmingIgnore}
        open={confirmingIgnore}
        pending={busy}
        title="Ignorer cet incident ?"
      />
    </article>
  );
}
