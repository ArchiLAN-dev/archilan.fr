"use client";

import { useQuery } from "@tanstack/react-query";
import { KeyRound } from "lucide-react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { PelleAmount } from "@/features/wallet/pelle-amount";
import { fetchPasseurJournal, type PasseurJournalScope, type PasseurOpening } from "./passeur-api";

const WHEN = new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit", timeZone: "Europe/Paris" });

const STATUS: Record<PasseurOpening["status"], { label: string; tone: string }> = {
  opened: { label: "Ouvert", tone: "text-success" },
  failed: { label: "Échec, remboursé", tone: "text-danger" },
  pending: { label: "En cours", tone: "text-muted-foreground" },
};

/**
 * « Journal du Passeur » (story 52.5): every call, opened or failed. The staff read an event's or a session's, the
 * owner of a private run reads theirs. Hidden when the Passeur was never called.
 */
export function PasseurJournal({ scope }: { scope: PasseurJournalScope }) {
  const { data } = useQuery({
    queryKey: ["passeur-journal", scope],
    queryFn: () => fetchPasseurJournal(scope),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (!data || data.length === 0) return null;

  return <PasseurJournalView openings={data} />;
}

export function PasseurJournalView({ openings }: { openings: PasseurOpening[] }) {
  const opened = openings.filter((opening) => opening.status === "opened");
  const kind = openings[0]?.kind ?? "event";
  const spent = opened.reduce((sum, opening) => sum + opening.price, 0);
  const plural = (n: number) => (n > 1 ? "s" : "");

  return (
    <section aria-labelledby="passeur-journal-title" className="grid gap-3 rounded border border-border bg-surface p-4">
      <h2 className="flex items-center gap-2 font-heading text-sm font-semibold text-foreground" id="passeur-journal-title">
        <KeyRound aria-hidden className="size-4 text-violet-400" />
        Journal du Passeur
      </h2>
      <p className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
        {opened.length} coffre{plural(opened.length)} ouvert{plural(opened.length)} sur {openings.length} appel{plural(openings.length)},
        <PelleAmount amount={spent} kind={kind} /> payées.
      </p>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[40rem] text-left text-xs">
          <thead className="text-muted-foreground">
            <tr className="border-b border-border">
              <th className="py-1.5 pr-3 font-medium">Quand</th>
              <th className="py-1.5 pr-3 font-medium">Pour</th>
              <th className="py-1.5 pr-3 font-medium">Monde de</th>
              <th className="py-1.5 pr-3 font-medium">Emplacement</th>
              <th className="py-1.5 pr-3 font-medium">Objet</th>
              <th className="py-1.5 pr-3 text-right font-medium">Prix</th>
              <th className="py-1.5 pr-3 text-right font-medium">Part</th>
              <th className="py-1.5 font-medium">Statut</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {openings.map((opening) => (
              <tr key={opening.id}>
                <td className="whitespace-nowrap py-1.5 pr-3 text-muted-foreground">{WHEN.format(new Date(opening.createdAt))}</td>
                <td className="py-1.5 pr-3 text-foreground">{opening.buyerName || "?"}</td>
                <td className="py-1.5 pr-3 text-foreground">{opening.absentName || "?"}</td>
                <td className="py-1.5 pr-3">{opening.locationName}</td>
                <td className="py-1.5 pr-3">{opening.itemName}</td>
                <td className="py-1.5 pr-3 text-right">
                  <PelleAmount amount={opening.price} kind={opening.kind} />
                </td>
                <td className="py-1.5 pr-3 text-right">
                  <PelleAmount amount={opening.share} kind={opening.kind} />
                </td>
                <td className={`py-1.5 ${STATUS[opening.status].tone}`} title={opening.failureCode ?? undefined}>
                  {STATUS[opening.status].label}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
