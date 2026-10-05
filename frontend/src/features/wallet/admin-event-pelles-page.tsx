"use client";

import Link from "next/link";
import { use, useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, Loader2, Shovel } from "lucide-react";

import { ConfirmDialog, ConfirmFigure } from "@/components/ui/confirm-dialog";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { PelleAmount, pellesLabel } from "./pelle-amount";
import { distributeEventPelles, fetchEventPelles, type EventPelles } from "./event-pelles-api";

const MAX_AMOUNT = 1000;
const dateFormatter = new Intl.DateTimeFormat("fr-FR", { dateStyle: "long", timeStyle: "short", timeZone: "Europe/Paris" });

/** What the admin confirms before handing pelles out (story 41.2 AC2, summarised in the modal since story 39.15). */
export type DistributionPreview = { amount: number; members: number; total: number; label: string };

export function distributionPreview(amount: number, members: number, label: string): DistributionPreview {
  return { amount, members, total: amount * members, label };
}

/** The figures of a distribution: per member, how many, in all - and the label the members will read. */
export function DistributionSummary({ preview }: { preview: DistributionPreview }) {
  return (
    <div className="grid gap-4">
      <div className="grid grid-cols-3 gap-3">
        <ConfirmFigure label="Par membre">
          <PelleAmount amount={preview.amount} />
        </ConfirmFigure>
        <ConfirmFigure label={preview.members > 1 ? "Membres" : "Membre"}>{preview.members}</ConfirmFigure>
        <ConfirmFigure label="Total">
          <PelleAmount amount={preview.total} className="text-success" />
        </ConfirmFigure>
      </div>
      <div className="grid gap-1 border-t border-border pt-3">
        <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Libellé, visible par les membres</span>
        <p className="text-sm text-foreground">{preview.label}</p>
      </div>
    </div>
  );
}

/** The outcome in words: who got pelles, and who did not. */
export function distributionSummary(result: { credited: number; skipped: number; alreadyCredited: number }): string {
  const parts = [`${result.credited} ${result.credited > 1 ? "membres crédités" : "membre crédité"}`];
  if (result.alreadyCredited > 0) {
    parts.push(`${result.alreadyCredited} ${result.alreadyCredited > 1 ? "déjà crédités" : "déjà crédité"} par cette distribution`);
  }
  if (result.skipped > 0) parts.push(`${result.skipped} ${result.skipped > 1 ? "sautés" : "sauté"} (compte banni ou supprimé)`);
  return `${parts.join(", ")}.`;
}

/**
 * The pelles of one event (story 41.2): who holds what, and the distribution to every active registrant or to
 * a selection. Once the event is over, its pelles have expired and only the outcome is shown.
 */
export function AdminEventPellesPage({ params }: { params: Promise<{ eventId: string }> }) {
  const { eventId } = use(params);
  const { data, isLoading } = useQuery({
    queryKey: ["admin-event-pelles", eventId],
    queryFn: () => fetchEventPelles(eventId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  return (
    <div className="grid gap-6 p-6 md:p-8">
      <Link className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground" href="/admin/evenements">
        <ArrowLeft aria-hidden className="size-4" />
        Événements
      </Link>
      {isLoading ? <p className="text-sm text-muted-foreground">Chargement…</p> : null}
      {!isLoading && !data ? <p className="text-sm text-danger">Impossible de charger les pelles de cet événement.</p> : null}
      {data ? <EventPellesView data={data} /> : null}
    </div>
  );
}

export function EventPellesView({ data }: { data: EventPelles }) {
  const queryClient = useQueryClient();
  const [amount, setAmount] = useState("");
  const [label, setLabel] = useState("");
  const [mode, setMode] = useState<"all" | "selection">("all");
  const [selected, setSelected] = useState<Set<string>>(() => new Set());
  const [pending, setPending] = useState(false);
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string } | null>(null);
  const [confirming, setConfirming] = useState<DistributionPreview | null>(null);
  // One id per distribution, kept across a retry so a double submit credits nobody twice.
  const requestIdRef = useRef<string | null>(null);

  const eligible = data.participants.filter((p) => !p.banned);
  const recipients = mode === "all" ? eligible.length : selected.size;
  const parsedAmount = Number.parseInt(amount, 10);
  const amountValid = Number.isInteger(parsedAmount) && parsedAmount >= 1 && parsedAmount <= MAX_AMOUNT;
  const canSubmit = !data.ended && !pending && amountValid && label.trim() !== "" && recipients > 0;

  function toggle(userId: string): void {
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(userId)) next.delete(userId);
      else next.add(userId);
      return next;
    });
  }

  function submit(): void {
    if (!canSubmit) return;
    setConfirming(distributionPreview(parsedAmount, recipients, label.trim()));
  }

  async function distribute(): Promise<void> {
    requestIdRef.current ??= crypto.randomUUID();

    setPending(true);
    setMessage(null);
    const result = await distributeEventPelles(data.eventId, {
      amount: parsedAmount,
      label,
      requestId: requestIdRef.current,
      userIds: mode === "all" ? null : [...selected],
    });
    if (result.kind === "ok") {
      requestIdRef.current = null;
      setMessage({ tone: "ok", text: distributionSummary(result) });
      setAmount("");
      setLabel("");
      setSelected(new Set());
      await queryClient.invalidateQueries({ queryKey: ["admin-event-pelles", data.eventId] });
    } else {
      setMessage({ tone: "error", text: result.message });
    }
    setPending(false);
    setConfirming(null);
  }

  const fieldClass = "min-h-9 rounded-lg border border-border bg-background px-3 text-sm text-foreground";

  return (
    <div className="grid gap-6">
      <header className="grid gap-1">
        <h1 className="flex items-center gap-2 font-heading text-2xl font-bold text-foreground">
          <Shovel aria-hidden className="size-6 text-accent-text" />
          Pelles · {data.eventTitle}
        </h1>
        <p className="text-sm text-muted-foreground">
          {data.ended
            ? `Terminé le ${dateFormatter.format(new Date(data.endsAt))} : 10 % des pelles restantes sont passées en or, le reste a été détruit.`
            : `Les pelles de cet événement servent jusqu'au ${dateFormatter.format(new Date(data.endsAt))} ; ensuite 10 % passent en or et le reste disparaît.`}
        </p>
      </header>

      <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Figure label="Distribuées" value={data.distributed} />
        <Figure label="En circulation" value={data.inCirculation} />
        {data.ended ? <Figure label="Converties en or" value={data.converted} /> : null}
        {data.ended ? <Figure label="Détruites" value={data.destroyed} /> : null}
      </dl>

      {data.ended ? null : (
        <form
          className="grid gap-3 rounded-xl border border-border p-4 sm:grid-cols-2"
          onSubmit={(event) => {
            event.preventDefault();
            submit();
          }}
        >
          <h2 className="font-heading text-lg font-semibold text-foreground sm:col-span-2">Distribuer</h2>
          <label className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">Pelles par membre (1 à {MAX_AMOUNT})</span>
            <input className={fieldClass} inputMode="numeric" max={MAX_AMOUNT} min={1} onChange={(e) => setAmount(e.target.value)} type="number" value={amount} />
          </label>
          <label className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">Destinataires</span>
            <select className={fieldClass} onChange={(e) => setMode(e.target.value === "selection" ? "selection" : "all")} value={mode}>
              <option value="all">Tous les inscrits ({eligible.length})</option>
              <option value="selection">Une sélection</option>
            </select>
          </label>
          <label className="grid gap-1 text-sm sm:col-span-2">
            <span className="font-medium text-foreground">Libellé (visible par les membres)</span>
            <input className={fieldClass} maxLength={200} onChange={(e) => setLabel(e.target.value)} placeholder="Happening du samedi" type="text" value={label} />
          </label>
          <div className="sm:col-span-2">
            <button
              className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-40"
              disabled={!canSubmit}
              type="submit"
            >
              {pending ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <Shovel aria-hidden className="size-4" />}
              Distribuer à {recipients} {recipients > 1 ? "membres" : "membre"}
            </button>
          </div>
          {message !== null ? (
            <p className={`text-sm sm:col-span-2 ${message.tone === "ok" ? "text-success" : "text-danger"}`}>{message.text}</p>
          ) : null}
        </form>
      )}

      <section aria-labelledby="event-pelles-members" className="grid gap-3">
        <h2 className="font-heading text-lg font-semibold text-foreground" id="event-pelles-members">
          Inscrits ({data.participants.length})
        </h2>
        {data.participants.length === 0 ? (
          <p className="text-sm text-muted-foreground">Aucun inscrit actif.</p>
        ) : (
          <ul className="divide-y divide-border rounded-xl border border-border">
            {data.participants.map((participant) => (
              <li className="flex items-center justify-between gap-3 px-4 py-2.5 text-sm" key={participant.userId}>
                <label className="flex min-w-0 items-center gap-2.5">
                  {mode === "selection" && !data.ended ? (
                    <input
                      checked={selected.has(participant.userId)}
                      disabled={participant.banned}
                      onChange={() => toggle(participant.userId)}
                      type="checkbox"
                    />
                  ) : null}
                  <span className="truncate text-foreground">{participant.displayName}</span>
                  {participant.banned ? <span className="text-xs text-danger">banni</span> : null}
                </label>
                <PelleAmount amount={participant.balance} className="shrink-0 font-semibold" />
              </li>
            ))}
          </ul>
        )}
      </section>

      <ConfirmDialog
        confirmLabel={`Distribuer ${pellesLabel(confirming?.total ?? 0)}`}
        description={`Chaque membre reçoit ses pelles de ${data.eventTitle} tout de suite, avec ce libellé dans son historique.`}
        icon={Shovel}
        onConfirm={() => void distribute()}
        onOpenChange={(open) => {
          if (!open && !pending) setConfirming(null);
        }}
        open={confirming !== null}
        pending={pending}
        title="Distribuer des pelles ?"
      >
        {confirming !== null ? <DistributionSummary preview={confirming} /> : null}
      </ConfirmDialog>
    </div>
  );
}

function Figure({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-xl border border-border p-4">
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd className="mt-1 font-heading text-2xl font-bold">
        <PelleAmount amount={value} />
      </dd>
    </div>
  );
}
