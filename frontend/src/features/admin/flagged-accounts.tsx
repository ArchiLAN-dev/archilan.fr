"use client";

import Link from "next/link";
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ExternalLink, Gavel, History, Loader2, ShieldAlert } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { Dialog, DialogBody } from "@/components/ui/dialog";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";

import type { FlaggedAccount } from "./admin-moderation-api";
import { fetchAdminUserModeration } from "./admin-users-api";
import { ModerationActionList, ModerationStateBanner } from "./moderation-history";
import { SanctionDialog } from "./sanction-dialog";

type Props = { accounts: FlaggedAccount[]; threshold: number; onActed: () => void };

/**
 * Story 39.11 : les comptes au-delà du seuil, une ligne chacun. La sanction et l'historique s'ouvrent dans
 * leur propre fenêtre au lieu de se déplier dans la ligne.
 */
export function FlaggedAccounts({ accounts, threshold, onActed }: Props) {
  const [sanctioning, setSanctioning] = useState<FlaggedAccount | null>(null);
  const [historyOf, setHistoryOf] = useState<FlaggedAccount | null>(null);

  return (
    <section aria-label="Comptes à examiner" className="grid gap-2">
      <h3 className="flex items-center gap-2 text-sm font-semibold text-accent-warm">
        <ShieldAlert aria-hidden className="size-4" /> À examiner - comptes au-delà du seuil ({threshold})
      </h3>
      <ul className="divide-y divide-border rounded-lg border border-accent-warm/40 bg-accent-warm/5" role="list">
        {accounts.map((account) => (
          <li className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3" key={account.userId}>
            <div className="grid min-w-0 gap-0.5">
              {account.slug !== null ? (
                <Link className="truncate text-sm font-semibold text-foreground hover:text-accent-text" href={`/joueurs/${account.slug}`}>
                  {accountName(account)}
                </Link>
              ) : (
                <span className="text-sm text-muted-foreground">Compte supprimé</span>
              )}
              <span className="text-xs text-muted-foreground">
                score <strong className="text-accent-warm">{account.score}</strong> · {account.reportCount} signalement
                {account.reportCount > 1 ? "s" : ""}
              </span>
            </div>
            <div className="flex flex-wrap gap-2">
              <button className={buttonVariants({ variant: "ghost" })} onClick={() => setHistoryOf(account)} type="button">
                <History aria-hidden className="size-4" /> Historique
              </button>
              <button className={buttonVariants({ variant: "secondary" })} onClick={() => setSanctioning(account)} type="button">
                <Gavel aria-hidden className="size-4" /> Sanctionner
              </button>
            </div>
          </li>
        ))}
      </ul>

      {sanctioning !== null ? (
        <SanctionDialog
          name={accountName(sanctioning)}
          onDone={onActed}
          onOpenChange={(open) => (open ? undefined : setSanctioning(null))}
          open
          userId={sanctioning.userId}
        />
      ) : null}

      {historyOf !== null ? <HistoryPanel account={historyOf} onClose={() => setHistoryOf(null)} /> : null}
    </section>
  );
}

function HistoryPanel({ account, onClose }: { account: FlaggedAccount; onClose: () => void }) {
  const { data, isPending } = useQuery({
    queryKey: ["admin-user-moderation", account.userId],
    queryFn: () => fetchAdminUserModeration(account.userId),
    staleTime: DEFAULT_STALE_TIME,
  });

  return (
    <Dialog onOpenChange={(open) => (open ? undefined : onClose())} open title={`Historique de ${accountName(account)}`} variant="side">
      <DialogBody>
        {isPending ? (
          <p className="flex items-center gap-2 text-sm text-muted-foreground">
            <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement…
          </p>
        ) : data === null || data === undefined ? (
          <p className="text-sm text-muted-foreground">Impossible de charger la modération de ce compte.</p>
        ) : (
          <>
            <ModerationStateBanner moderation={data} />
            <div className="grid gap-2">
              <h4 className="text-sm font-semibold text-foreground">Actions</h4>
              <ModerationActionList actions={data.actions} />
            </div>
          </>
        )}
        <Link className={buttonVariants({ variant: "secondary", className: "justify-self-start" })} href={`/admin/utilisateurs/${account.userId}`}>
          <ExternalLink aria-hidden className="size-4" /> Ouvrir la fiche
        </Link>
      </DialogBody>
    </Dialog>
  );
}

function accountName(account: FlaggedAccount): string {
  return account.displayName ?? account.slug ?? "ce compte";
}
