"use client";

import { useState } from "react";
import Link from "next/link";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { ChevronLeft, ChevronRight } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { PelleAmount } from "./pelle-amount";
import { fetchMyWallet, pelleReasonLabel, type Wallet } from "./wallet-api";
import { WeeklyQuestsPanel } from "./weekly-quests";

const dateFormatter = new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium", timeStyle: "short" });

/**
 * « Mon portefeuille » (story 41.1 AC5): the gold balance, the pelles held for each event, and the history
 * of every movement. Private: only its owner sees it.
 */
export function WalletPanel() {
  const [page, setPage] = useState(1);
  const { data, isLoading } = useQuery({
    queryKey: ["my-wallet", page],
    queryFn: () => fetchMyWallet(page),
    staleTime: DEFAULT_STALE_TIME,
    placeholderData: keepPreviousData,
    retry: false,
  });

  if (isLoading) {
    return <p className="text-sm text-muted-foreground">Chargement du portefeuille…</p>;
  }
  if (!data) {
    return <p className="text-sm text-danger">Impossible de charger ton portefeuille pour le moment.</p>;
  }

  return (
    <div className="grid gap-6">
      <WalletView onPage={setPage} wallet={data} />
      {/* Story 41.6. */}
      <WeeklyQuestsPanel />
    </div>
  );
}

export function WalletView({ wallet, onPage }: { wallet: Wallet; onPage: (page: number) => void }) {
  const { history } = wallet;
  const pages = Math.max(1, Math.ceil(history.total / history.perPage));

  return (
    <div className="grid gap-6">
      <section aria-labelledby="wallet-balance" className="card-glow grid gap-4 rounded-xl border border-border p-5">
        <h2 className="font-heading text-xl font-semibold text-foreground" id="wallet-balance">
          Mes pelles
        </h2>
        <div className="flex flex-wrap items-end gap-x-8 gap-y-4">
          <div>
            <p className="text-sm text-muted-foreground">Pelles d&apos;or</p>
            <PelleAmount amount={wallet.gold} className="font-heading text-4xl font-bold text-warning" />
          </div>
          {wallet.events.map((event) => (
            <div key={event.eventId}>
              <p className="text-sm text-muted-foreground">{event.eventTitle}</p>
              <PelleAmount amount={event.balance} className="font-heading text-2xl font-semibold text-foreground" />
            </div>
          ))}
        </div>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <p className="text-xs text-muted-foreground">
            Les pelles d&apos;or se gardent. Les pelles d&apos;un événement ne servent que pendant cet événement.
          </p>
          {/* Story 41.12: the way to the shop. */}
          <Link className={buttonVariants({ variant: "primary" })} href="/boutique">
            Dépenser mes pelles
          </Link>
        </div>
      </section>

      <section aria-labelledby="wallet-history" className="grid gap-3">
        <h2 className="font-heading text-xl font-semibold text-foreground" id="wallet-history">
          Historique
        </h2>
        {history.items.length === 0 ? (
          <p className="text-sm text-muted-foreground">Aucun mouvement pour le moment.</p>
        ) : (
          <ul className="divide-y divide-border rounded-xl border border-border">
            {history.items.map((movement) => (
              <li className="flex items-start justify-between gap-4 px-4 py-3" key={movement.id}>
                <div className="min-w-0">
                  <p className="text-sm font-medium text-foreground">{movement.label}</p>
                  <p className="text-xs text-muted-foreground">
                    {pelleReasonLabel(movement.reason)}
                    {movement.eventTitle !== null ? ` · ${movement.eventTitle}` : ""}
                    {" · "}
                    <time dateTime={movement.createdAt}>{dateFormatter.format(new Date(movement.createdAt))}</time>
                  </p>
                </div>
                <PelleAmount
                  amount={movement.amount}
                  className={`shrink-0 text-sm font-semibold ${movement.amount > 0 ? "text-success" : "text-danger"}`}
                  signed
                />
              </li>
            ))}
          </ul>
        )}
        {pages > 1 ? (
          <nav aria-label="Pages de l'historique" className="flex items-center justify-between gap-3 text-sm">
            <button
              className="inline-flex min-h-9 items-center gap-1 rounded-lg border border-border px-3 font-semibold disabled:opacity-40"
              disabled={history.page <= 1}
              onClick={() => onPage(history.page - 1)}
              type="button"
            >
              <ChevronLeft aria-hidden className="size-4" />
              Plus récents
            </button>
            <span className="text-muted-foreground">
              Page {history.page} sur {pages}
            </span>
            <button
              className="inline-flex min-h-9 items-center gap-1 rounded-lg border border-border px-3 font-semibold disabled:opacity-40"
              disabled={history.page >= pages}
              onClick={() => onPage(history.page + 1)}
              type="button"
            >
              Plus anciens
              <ChevronRight aria-hidden className="size-4" />
            </button>
          </nav>
        ) : null}
      </section>
    </div>
  );
}
