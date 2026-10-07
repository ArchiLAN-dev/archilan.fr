"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowRight, Loader2, Shovel } from "lucide-react";

import { ConfirmDialog, ConfirmFigure } from "@/components/ui/confirm-dialog";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchAdminEvents } from "@/features/admin/admin-events-api";
import { SheetSection } from "@/features/admin/admin-sheet-section";
import { PelleAmount, pellesLabel } from "./pelle-amount";
import { adjustMemberPelles, fetchMemberWallet, type Wallet } from "./wallet-api";

const MAX_AMOUNT = 10000;

/** The balance a movement applies to: gold, or one event's pelles. */
export function balanceFor(wallet: Wallet, kind: "gold" | "event", eventId: string | null): number {
  if (kind === "gold") return wallet.gold;
  return wallet.events.find((event) => event.eventId === eventId)?.balance ?? 0;
}

/** What an admin confirms before moving pelles (story 41.1 AC7, summarised in the modal since story 39.15). */
export type AdjustmentPreview = { direction: "credit" | "debit"; movement: number; before: number; after: number; kindLabel: string; reason: string };

export function adjustmentPreview(
  direction: "credit" | "debit",
  amount: number,
  before: number,
  kindLabel: string,
  reason: string,
): AdjustmentPreview {
  const movement = direction === "credit" ? amount : -amount;
  return { direction, movement, before, after: before + movement, kindLabel, reason };
}

/**
 * The pelles panel of the admin user sheet (story 41.1 AC7): the member's balances and the credit/debit
 * action. The server owns the rules (bounds, balance never below zero, never on one's own wallet); the form
 * only shows the balance before and after so the admin confirms with the numbers in front of them.
 */
export function AdminUserPelles({ userId, isSelf, memberName }: { userId: string; isSelf: boolean; memberName: string }) {
  const queryClient = useQueryClient();
  const [direction, setDirection] = useState<"credit" | "debit">("credit");
  const [amount, setAmount] = useState("");
  const [kind, setKind] = useState<"gold" | "event">("gold");
  const [eventId, setEventId] = useState("");
  const [reason, setReason] = useState("");
  const [pending, setPending] = useState(false);
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string } | null>(null);
  const [confirming, setConfirming] = useState<AdjustmentPreview | null>(null);

  const { data: wallet } = useQuery({
    queryKey: ["admin-member-wallet", userId],
    queryFn: () => fetchMemberWallet(userId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const { data: events } = useQuery({
    queryKey: ["admin-events"],
    queryFn: fetchAdminEvents,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    enabled: kind === "event",
  });

  const parsedAmount = Number.parseInt(amount, 10);
  const amountValid = Number.isInteger(parsedAmount) && parsedAmount >= 1 && parsedAmount <= MAX_AMOUNT;
  const canSubmit = !isSelf && !pending && amountValid && reason.trim() !== "" && (kind === "gold" || eventId !== "");

  function submit(): void {
    if (!canSubmit || !wallet) return;
    const target = kind === "event" ? eventId : null;
    const eventTitle = events?.kind === "ready" ? events.events.find((event) => event.id === target)?.title : undefined;
    const kindLabel = kind === "gold" ? "Pelles d'or" : `Pelles de ${eventTitle ?? "l'événement"}`;
    setConfirming(adjustmentPreview(direction, parsedAmount, balanceFor(wallet, kind, target), kindLabel, reason.trim()));
  }

  async function apply(): Promise<void> {
    const target = kind === "event" ? eventId : null;
    setPending(true);
    setMessage(null);
    const result = await adjustMemberPelles(userId, { direction, amount: parsedAmount, kind, eventId: target, reason });
    if (result.kind === "ok") {
      setMessage({ tone: "ok", text: `Fait. Nouveau solde : ${pellesLabel(result.balanceAfter)}.` });
      setAmount("");
      setReason("");
      await queryClient.invalidateQueries({ queryKey: ["admin-member-wallet", userId] });
      await queryClient.invalidateQueries({ queryKey: ["admin-user-activity", userId] });
    } else {
      setMessage({ tone: "error", text: result.message });
    }
    setPending(false);
    setConfirming(null);
  }

  const fieldClass = "min-h-9 rounded-lg border border-border bg-background px-3 text-sm text-foreground";

  return (
    <SheetSection description="Solde du membre, crédit ou débit avec un motif." icon={Shovel} id="pelles" title="Pelles">
      <div className="grid gap-4">
        {wallet ? (
          <div className="flex flex-wrap gap-x-6 gap-y-2 text-sm">
            <span>
              Or : <PelleAmount amount={wallet.gold} className="font-semibold text-warning" />
            </span>
            {wallet.events.map((event) => (
              <span key={event.eventId}>
                {event.eventTitle} : <PelleAmount amount={event.balance} className="font-semibold" />
              </span>
            ))}
          </div>
        ) : null}

        {isSelf ? (
          <p className="text-sm text-muted-foreground">Tu ne peux pas créditer ou débiter ton propre portefeuille.</p>
        ) : (
          <form
            className="grid gap-3 sm:grid-cols-2"
            onSubmit={(event) => {
              event.preventDefault();
              submit();
            }}
          >
            <label className="grid gap-1 text-sm">
              <span className="font-medium text-foreground">Opération</span>
              <select className={fieldClass} onChange={(e) => setDirection(e.target.value === "debit" ? "debit" : "credit")} value={direction}>
                <option value="credit">Créditer</option>
                <option value="debit">Débiter</option>
              </select>
            </label>
            <label className="grid gap-1 text-sm">
              <span className="font-medium text-foreground">Montant (1 à {MAX_AMOUNT})</span>
              <input className={fieldClass} inputMode="numeric" max={MAX_AMOUNT} min={1} onChange={(e) => setAmount(e.target.value)} type="number" value={amount} />
            </label>
            <label className="grid gap-1 text-sm">
              <span className="font-medium text-foreground">Type</span>
              <select className={fieldClass} onChange={(e) => setKind(e.target.value === "event" ? "event" : "gold")} value={kind}>
                <option value="gold">Pelles d&apos;or</option>
                <option value="event">Pelles d&apos;événement</option>
              </select>
            </label>
            {kind === "event" ? (
              <label className="grid gap-1 text-sm">
                <span className="font-medium text-foreground">Événement</span>
                <select className={fieldClass} onChange={(e) => setEventId(e.target.value)} value={eventId}>
                  <option value="">Choisir…</option>
                  {(events?.kind === "ready" ? events.events : []).map((event) => (
                    <option key={event.id} value={event.id}>
                      {event.title}
                    </option>
                  ))}
                </select>
              </label>
            ) : null}
            <label className="grid gap-1 text-sm sm:col-span-2">
              <span className="font-medium text-foreground">Motif (visible par le membre)</span>
              <input className={fieldClass} maxLength={200} onChange={(e) => setReason(e.target.value)} type="text" value={reason} />
            </label>
            <div className="sm:col-span-2">
              <button
                className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-40"
                disabled={!canSubmit || !wallet}
                type="submit"
              >
                {pending ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <Shovel aria-hidden className="size-4" />}
                {direction === "credit" ? "Créditer" : "Débiter"}
              </button>
            </div>
          </form>
        )}

        {message !== null ? (
          <p className={`text-sm ${message.tone === "ok" ? "text-success" : "text-danger"}`}>{message.text}</p>
        ) : null}
      </div>

      <ConfirmDialog
        confirmLabel={`${direction === "credit" ? "Créditer" : "Débiter"} ${pellesLabel(Math.abs(confirming?.movement ?? 0))}`}
        description="Le mouvement apparaît dans l'historique du membre, avec ce motif."
        icon={Shovel}
        onConfirm={() => void apply()}
        onOpenChange={(open) => {
          if (!open && !pending) setConfirming(null);
        }}
        open={confirming !== null}
        pending={pending}
        title={`${direction === "credit" ? "Créditer" : "Débiter"} les pelles de ${memberName} ?`}
        tone={direction === "debit" ? "danger" : "default"}
      >
        {confirming !== null ? <AdjustmentSummary preview={confirming} /> : null}
      </ConfirmDialog>
    </SheetSection>
  );
}

/** The figures of a credit or debit: the movement, the balance before and after, the reason (story 39.15). */
export function AdjustmentSummary({ preview }: { preview: AdjustmentPreview }) {
  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <PelleAmount
          amount={preview.movement}
          className={`font-heading text-3xl font-bold ${preview.direction === "credit" ? "text-success" : "text-danger"}`}
          signed
        />
        <span className="text-sm text-muted-foreground">{preview.kindLabel}</span>
      </div>
      <div className="flex items-end gap-4">
        <ConfirmFigure label="Solde actuel">
          <PelleAmount amount={preview.before} />
        </ConfirmFigure>
        <ArrowRight aria-label="devient" className="mb-1.5 size-4 shrink-0 text-muted-foreground" />
        <ConfirmFigure label="Nouveau solde">
          <PelleAmount amount={preview.after} />
        </ConfirmFigure>
      </div>
      <div className="grid gap-1 border-t border-border pt-3">
        <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Motif, visible par le membre</span>
        <p className="text-sm text-foreground">{preview.reason}</p>
      </div>
    </div>
  );
}
