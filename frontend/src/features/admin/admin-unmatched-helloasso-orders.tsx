"use client";

import { CheckCircle2, Link2, RefreshCw, Search, X } from "lucide-react";
import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  fetchUnmatchedHelloAssoOrders,
  reconcileHelloAssoOrder,
  triggerHelloAssoSync,
  type UnmatchedHelloAssoOrder,
} from "./admin-unmatched-helloasso-api";
import {
  searchUsersForMembership,
  type UserSearchResult,
} from "./admin-membership-api";

function formatAmount(cents: number): string {
  return (cents / 100).toFixed(2).replace(".", ",") + " €";
}

function formatDate(dateStr: string | null): string {
  if (!dateStr) return "-";
  try {
    return new Intl.DateTimeFormat("fr-FR", {
      dateStyle: "medium",
      timeStyle: "short",
    }).format(new Date(dateStr));
  } catch {
    return dateStr;
  }
}

// ── ReconcilePanel ────────────────────────────────────────────────────────────

type ReconcilePanelProps = {
  order: UnmatchedHelloAssoOrder;
  onSuccess: () => void;
  onCancel: () => void;
};

function ReconcilePanel({ order, onSuccess, onCancel }: ReconcilePanelProps) {
  const [userSearch, setUserSearch] = useState("");
  const [searchResults, setSearchResults] = useState<UserSearchResult[]>([]);
  const [selectedUser, setSelectedUser] = useState<UserSearchResult | null>(null);
  const [isSearching, setIsSearching] = useState(false);

  const { mutate: reconcile, isPending } = useMutation({
    mutationFn: ({ userId }: { userId: string }) =>
      reconcileHelloAssoOrder(order.helloassoOrderId, userId),
    onSuccess: (ok) => {
      if (ok) onSuccess();
    },
  });

  async function handleSearch(q: string) {
    setUserSearch(q);
    setSelectedUser(null);
    if (q.trim().length < 2) {
      setSearchResults([]);
      return;
    }
    setIsSearching(true);
    const results = await searchUsersForMembership(q.trim());
    setSearchResults(results);
    setIsSearching(false);
  }

  return (
    <div className="mt-4 grid gap-3 border-t border-border pt-4">
      <p className="text-sm font-medium text-foreground">
        Rattacher à un compte ArchiLAN
      </p>

      {selectedUser === null ? (
        <div className="grid gap-1">
          <span className="flex min-h-11 items-center gap-2 border border-border bg-background px-3 focus-within:border-accent">
            <Search aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
            <input
              type="text"
              value={userSearch}
              onChange={(e) => {
                void handleSearch(e.target.value);
              }}
              placeholder="Nom, email ou pseudo…"
              className="min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
            />
          </span>

          {isSearching && (
            <p className="px-1 text-xs text-muted-foreground">Recherche…</p>
          )}

          {!isSearching && searchResults.length > 0 && (
            <div className="border border-border bg-background">
              {searchResults.map((user) => (
                <button
                  key={user.id}
                  type="button"
                  onClick={() => {
                    setSelectedUser(user);
                    setSearchResults([]);
                    setUserSearch(user.displayName ?? user.email);
                  }}
                  className="flex w-full flex-col border-b border-border px-3 py-2.5 text-left transition-colors hover:bg-surface last:border-b-0 focus:bg-surface focus:outline-none"
                >
                  <span className="text-sm font-medium text-foreground">
                    {user.displayName ?? "-"}
                  </span>
                  <span className="text-xs text-muted-foreground">{user.email}</span>
                </button>
              ))}
            </div>
          )}
        </div>
      ) : (
        <div className="flex min-h-11 items-center justify-between border border-accent/60 bg-surface px-3">
          <div>
            <p className="text-sm font-semibold text-foreground">
              {selectedUser.displayName ?? "-"}
            </p>
            <p className="text-xs text-muted-foreground">{selectedUser.email}</p>
          </div>
          <button
            type="button"
            onClick={() => {
              setSelectedUser(null);
              setUserSearch("");
            }}
            className="ml-2 shrink-0 text-muted-foreground transition-colors hover:text-foreground"
            aria-label="Désélectionner"
          >
            <X aria-hidden="true" className="size-4" />
          </button>
        </div>
      )}

      <div className="flex gap-2">
        <button
          type="button"
          disabled={selectedUser === null || isPending}
          onClick={() => {
            if (selectedUser !== null) {
              reconcile({ userId: selectedUser.id });
            }
          }}
          className="inline-flex min-h-10 flex-1 items-center justify-center border border-accent bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50"
        >
          {isPending ? "Rattachement…" : "Confirmer le rattachement"}
        </button>
        <button
          type="button"
          onClick={onCancel}
          disabled={isPending}
          className="inline-flex min-h-10 items-center justify-center border border-border px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:opacity-50"
        >
          Annuler
        </button>
      </div>
    </div>
  );
}

// ── Main component ────────────────────────────────────────────────────────────

export function AdminUnmatchedHelloAssoOrders() {
  const queryClient = useQueryClient();

  const { data: orders, isLoading, isError } = useQuery({
    queryKey: ["admin", "helloasso-orders", "unmatched"],
    queryFn: fetchUnmatchedHelloAssoOrders,
    staleTime: DEFAULT_STALE_TIME,
  });

  const [openOrderId, setOpenOrderId] = useState<number | null>(null);
  const [banner, setBanner] = useState<{
    kind: "success" | "error";
    text: string;
  } | null>(null);

  const { mutate: sync, isPending: isSyncing } = useMutation({
    mutationFn: triggerHelloAssoSync,
    onSuccess: (ok) => {
      if (ok) {
        setBanner({
          kind: "success",
          text: "Synchronisation lancée. La liste se met à jour dans quelques secondes…",
        });
        setTimeout(() => {
          void queryClient.invalidateQueries({
            queryKey: ["admin", "helloasso-orders", "unmatched"],
          });
        }, 4000);
      } else {
        setBanner({ kind: "error", text: "Impossible de lancer la synchronisation." });
      }
    },
  });

  function handleSuccess() {
    setBanner({ kind: "success", text: "Adhésion activée avec succès." });
    setOpenOrderId(null);
    void queryClient.invalidateQueries({
      queryKey: ["admin", "helloasso-orders", "unmatched"],
    });
  }

  if (isLoading) {
    return (
      <div aria-busy="true" className="grid gap-3 border border-border bg-surface p-5">
        <div className="h-4 w-32 bg-surface-2" />
        <div className="h-16 bg-surface-2" />
        <div className="h-16 bg-surface-2" />
        <div className="h-16 bg-surface-2" />
      </div>
    );
  }

  if (isError || orders == null) {
    return (
      <p className="border border-danger/50 bg-surface p-3 text-sm text-danger" role="alert">
        Impossible de charger les paiements non rattachés.
      </p>
    );
  }

  return (
    <div className="grid gap-6">
      {/* ── Stats + sync ─────────────────────────────────────────────── */}
      <div className="flex flex-wrap items-center justify-between gap-4 border border-border bg-surface p-4">
        <div>
          <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">
            En attente
          </p>
          <p className="mt-1 font-heading text-3xl font-bold text-foreground">
            {orders.length}
          </p>
          <p className="mt-0.5 text-sm text-muted-foreground">
            paiement{orders.length !== 1 ? "s" : ""} non rattaché
            {orders.length !== 1 ? "s" : ""}
          </p>
        </div>

        <button
          type="button"
          onClick={() => sync()}
          disabled={isSyncing}
          className="inline-flex min-h-11 items-center gap-2 border border-border bg-background px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-40"
        >
          <RefreshCw
            aria-hidden="true"
            className={`size-4 ${isSyncing ? "animate-spin" : ""}`}
          />
          {isSyncing ? "Synchronisation…" : "Synchroniser HelloAsso"}
        </button>
      </div>

      {/* ── Banner ───────────────────────────────────────────────────── */}
      {banner !== null && (
        <div
          className={`flex items-center gap-3 border p-3 text-sm ${
            banner.kind === "success"
              ? "border-success/50 bg-surface text-success"
              : "border-danger/50 bg-surface text-danger"
          }`}
          role={banner.kind === "success" ? "status" : "alert"}
        >
          <span className="flex-1">{banner.text}</span>
          <button
            type="button"
            onClick={() => setBanner(null)}
            className="shrink-0 opacity-70 transition-opacity hover:opacity-100"
            aria-label="Fermer"
          >
            <X aria-hidden="true" className="size-4" />
          </button>
        </div>
      )}

      {/* ── Order list ───────────────────────────────────────────────── */}
      {orders.length === 0 ? (
        <div className="grid justify-items-center gap-3 border border-border bg-surface p-8 text-center">
          <CheckCircle2 aria-hidden="true" className="size-8 text-success" />
          <h2 className="font-heading text-2xl font-semibold text-foreground">
            Tout est à jour
          </h2>
          <p className="max-w-md text-sm leading-6 text-muted-foreground">
            Aucun paiement HelloAsso en attente de rattachement.
          </p>
        </div>
      ) : (
        <ul className="grid gap-3">
          {orders.map((order) => (
            <li key={order.helloassoOrderId} className="border border-border bg-surface">
              <div className="flex flex-wrap items-start justify-between gap-3 p-4">
                <div className="grid gap-1">
                  <p className="font-semibold text-foreground">
                    {[order.payerFirstName, order.payerLastName]
                      .filter(Boolean)
                      .join(" ") || "Payeur inconnu"}
                  </p>
                  <p className="text-sm text-muted-foreground">
                    {order.payerEmail ?? "Email non disponible"}
                  </p>
                  <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span className="inline-flex items-center border border-border px-2 py-0.5 text-xs font-semibold text-muted-foreground">
                      Réf. #{order.helloassoOrderId}
                    </span>
                    <span className="text-sm font-semibold text-foreground">
                      {formatAmount(order.amountCents)}
                    </span>
                    <span className="text-xs text-muted-foreground">
                      {formatDate(order.paidAt)}
                    </span>
                  </div>
                </div>

                {openOrderId !== order.helloassoOrderId && (
                  <button
                    type="button"
                    onClick={() => setOpenOrderId(order.helloassoOrderId)}
                    className="inline-flex min-h-9 items-center gap-1.5 border border-accent bg-accent px-3 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
                  >
                    <Link2 aria-hidden="true" className="size-3.5" />
                    Rattacher
                  </button>
                )}
              </div>

              {openOrderId === order.helloassoOrderId && (
                <div className="px-4 pb-4">
                  <ReconcilePanel
                    order={order}
                    onSuccess={handleSuccess}
                    onCancel={() => setOpenOrderId(null)}
                  />
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
