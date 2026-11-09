"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { KeyRound, X } from "lucide-react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { PelleAmount } from "@/features/wallet/pelle-amount";
import { dismissPasseurNotice, fetchPasseurNotice, passeurNoticeKey, type PasseurNotice } from "./passeur-api";

const WHEN = new Intl.DateTimeFormat("fr-FR", { weekday: "short", hour: "2-digit", minute: "2-digit", timeZone: "Europe/Paris" });

/**
 * « Pendant ton absence » (story 52.5): what the Passeur opened in the viewer's world, and what it brought them.
 * Shown until closed; the next opening brings it back. Nothing for a viewer whose world nobody opened.
 */
export function PasseurNoticeCard({ sessionId }: { sessionId: string }) {
  const queryClient = useQueryClient();
  const { data } = useQuery({
    queryKey: passeurNoticeKey(sessionId),
    queryFn: () => fetchPasseurNotice(sessionId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (!data || data.openings.length === 0) return null;

  return (
    <PasseurNoticeView
      notice={data}
      onDismiss={async () => {
        const closed = await dismissPasseurNotice(sessionId);
        if (closed) await queryClient.invalidateQueries({ queryKey: passeurNoticeKey(sessionId) });
        return closed;
      }}
    />
  );
}

export function PasseurNoticeView({ notice, onDismiss }: { notice: PasseurNotice; onDismiss: () => Promise<boolean> }) {
  const [pending, setPending] = useState(false);
  const [failed, setFailed] = useState(false);
  const count = notice.openings.length;

  async function dismiss(): Promise<void> {
    setPending(true);
    setFailed(!(await onDismiss()));
    setPending(false);
  }

  return (
    <section aria-labelledby="passeur-notice-title" className="mb-4 grid gap-3 rounded border border-violet-500/40 bg-surface p-4">
      <div className="flex items-start justify-between gap-3">
        <h2 className="flex items-center gap-2 font-heading text-sm font-semibold text-foreground" id="passeur-notice-title">
          <KeyRound aria-hidden className="size-4 shrink-0 text-violet-400" />
          Pendant ton absence, le Passeur a ouvert {count} emplacement{count > 1 ? "s" : ""} de ton monde
        </h2>
        <button
          aria-label="Fermer"
          className="inline-flex shrink-0 items-center rounded border border-border p-1 text-muted-foreground hover:text-foreground disabled:opacity-40"
          disabled={pending}
          onClick={() => void dismiss()}
          type="button"
        >
          <X aria-hidden className="size-3.5" />
        </button>
      </div>
      <ul className="divide-y divide-border">
        {notice.openings.map((opening) => (
          <li className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 py-2 text-sm" key={opening.id}>
            <span className="min-w-0">
              <span className="text-foreground">{opening.locationName}</span>
              <span className="text-muted-foreground">
                {" "}
                · {opening.itemName} pour {opening.buyerName}
              </span>
            </span>
            <span className="flex shrink-0 items-center gap-2 text-xs text-muted-foreground">
              {WHEN.format(new Date(opening.settledAt ?? opening.createdAt))}
              <PelleAmount amount={opening.share} kind={opening.kind} signed />
            </span>
          </li>
        ))}
      </ul>
      {notice.kind !== null ? (
        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
          Tu as reçu <PelleAmount amount={notice.total} className="font-semibold text-foreground" kind={notice.kind} /> au total.
        </p>
      ) : null}
      {failed ? <p className="text-xs text-danger">L&apos;encart n&apos;a pas pu être fermé.</p> : null}
    </section>
  );
}
