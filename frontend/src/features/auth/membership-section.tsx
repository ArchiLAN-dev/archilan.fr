"use client";

import { useQuery } from "@tanstack/react-query";
import { CheckCircle2, Clock, ExternalLink, XCircle } from "lucide-react";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  getAccountMembership,
  getAccountMembershipHistory,
  getMembershipCheckoutUrl,
} from "@/features/payments/membership-api";

export function MembershipSection() {
  const { data: membership, isLoading: loadingMembership } = useQuery({
    queryKey: ["account-membership"],
    queryFn: getAccountMembership,
    staleTime: DEFAULT_STALE_TIME,
  });

  const { data: checkoutUrl, isLoading: loadingCheckout } = useQuery({
    queryKey: ["membership-checkout-url"],
    queryFn: getMembershipCheckoutUrl,
    staleTime: DEFAULT_STALE_TIME,
  });

  const { data: history, isLoading: loadingHistory } = useQuery({
    queryKey: ["account-membership-history"],
    queryFn: getAccountMembershipHistory,
    staleTime: DEFAULT_STALE_TIME,
  });

  if (loadingMembership || loadingCheckout || loadingHistory) {
    return <MembershipSkeleton />;
  }

  const status = membership?.status ?? "none";

  return (
    <div className="grid gap-6">
      <StatusBanner status={status} />

      {(membership?.startedAt ?? membership?.expiresAt) && (
        <div className="grid grid-cols-2 gap-px border border-border bg-border">
          {membership?.startedAt && (
            <div className="bg-surface p-4">
              <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
                Membre depuis
              </p>
              <p className="mt-1 font-heading text-base font-semibold text-foreground">
                {formatDate(membership.startedAt)}
              </p>
            </div>
          )}
          {membership?.expiresAt && (
            <div className="bg-surface p-4">
              <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
                {status === "expired" ? "Expirée le" : "Expire le"}
              </p>
              <p
                className={[
                  "mt-1 font-heading text-base font-semibold",
                  status === "expired" ? "text-danger" : "text-foreground",
                ].join(" ")}
              >
                {formatDate(membership.expiresAt)}
              </p>
            </div>
          )}
        </div>
      )}

      {checkoutUrl && (
        <div>
          <a
            className={[
              "inline-flex min-h-10 items-center gap-2 border px-4 text-sm font-semibold transition-colors",
              status === "active"
                ? "border-border bg-surface text-foreground hover:border-accent hover:text-foreground"
                : "border-accent bg-accent text-white hover:bg-accent-hover",
            ].join(" ")}
            href={checkoutUrl}
            rel="noopener noreferrer"
            target="_blank"
          >
            <ExternalLink aria-hidden="true" className="size-4" />
            {status === "active" ? "Renouveler" : "Adhérer à ArchiLAN"}
          </a>
          {status !== "active" && (
            <p className="mt-2 text-xs text-muted-foreground">
              Paiement via HelloAsso - validation manuelle sous 24 h.
            </p>
          )}
        </div>
      )}

      {history && history.length > 1 && (
        <div>
          <h3 className="mb-3 font-heading text-sm font-semibold uppercase tracking-wider text-muted-foreground">
            Historique
          </h3>
          <div className="overflow-x-auto border border-border bg-surface">
            <table className="w-full border-collapse text-left text-sm">
              <thead className="border-b border-border bg-surface-2 text-xs font-medium uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-4 py-3">Statut</th>
                  <th className="px-4 py-3">Début</th>
                  <th className="px-4 py-3">Fin</th>
                  <th className="px-4 py-3">Source</th>
                </tr>
              </thead>
              <tbody>
                {history.map((entry) => (
                  <tr className="border-b border-border/50 last:border-b-0" key={entry.id}>
                    <td className="px-4 py-3">
                      <StatusBadge status={entry.status} />
                    </td>
                    <td className="px-4 py-3 text-foreground">
                      {entry.startedAt ? formatDate(entry.startedAt) : "-"}
                    </td>
                    <td className="px-4 py-3 text-foreground">
                      {entry.expiresAt ? formatDate(entry.expiresAt) : "-"}
                    </td>
                    <td className="px-4 py-3 text-muted-foreground capitalize">
                      {entry.source}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}

function StatusBanner({ status }: { status: "active" | "expired" | "none" }) {
  if (status === "active") {
    return (
      <div className="flex items-center gap-3 border border-success/30 bg-success/5 p-4">
        <CheckCircle2 aria-hidden="true" className="size-5 shrink-0 text-success" />
        <div>
          <p className="font-heading font-semibold text-foreground">Adhésion active</p>
          <p className="text-sm text-muted-foreground">Vous êtes membre ArchiLAN.</p>
        </div>
      </div>
    );
  }

  if (status === "expired") {
    return (
      <div className="flex items-center gap-3 border border-danger/30 bg-danger/5 p-4">
        <Clock aria-hidden="true" className="size-5 shrink-0 text-danger" />
        <div>
          <p className="font-heading font-semibold text-foreground">Adhésion expirée</p>
          <p className="text-sm text-muted-foreground">
            Renouvelez pour continuer à accéder aux événements.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="flex items-center gap-3 border border-border bg-surface p-4">
      <XCircle aria-hidden="true" className="size-5 shrink-0 text-muted-foreground" />
      <div>
        <p className="font-heading font-semibold text-foreground">Aucune adhésion</p>
        <p className="text-sm text-muted-foreground">
          Adhérez à ArchiLAN pour participer aux événements.
        </p>
      </div>
    </div>
  );
}

function StatusBadge({ status }: { status: string }) {
  if (status === "active") {
    return (
      <span className="inline-flex items-center gap-1.5 border border-success/40 bg-success/10 px-2 py-0.5 text-xs font-semibold text-success">
        Active
      </span>
    );
  }
  if (status === "expired") {
    return (
      <span className="inline-flex items-center gap-1.5 border border-border bg-surface-2 px-2 py-0.5 text-xs font-semibold text-muted-foreground">
        Expirée
      </span>
    );
  }
  return (
    <span className="inline-flex items-center border border-border bg-surface-2 px-2 py-0.5 text-xs font-semibold text-muted-foreground capitalize">
      {status}
    </span>
  );
}

function MembershipSkeleton() {
  return (
    <div className="grid gap-6">
      <div className="h-16 animate-pulse border border-border bg-surface" />
      <div className="grid grid-cols-2 gap-px border border-border bg-border">
        <div className="h-16 animate-pulse bg-surface" />
        <div className="h-16 animate-pulse bg-surface" />
      </div>
      <div className="h-10 w-40 animate-pulse bg-surface" />
    </div>
  );
}

function formatDate(iso: string): string {
  try {
    return new Intl.DateTimeFormat("fr-FR", {
      day: "numeric",
      month: "long",
      year: "numeric",
    }).format(new Date(iso));
  } catch {
    return iso;
  }
}
