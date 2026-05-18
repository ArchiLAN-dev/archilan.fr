"use client";

import { AlertTriangle, ChevronLeft, ChevronRight, Pencil, Plus, Search, Trash2, Users, X } from "lucide-react";
import Link from "next/link";
import type { ReactNode } from "react";
import { useEffect, useId, useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  fetchUserActiveMembership,
  createAdminMembership,
  deleteAdminMembership,
  fetchAdminMemberships,
  searchUsersForMembership,
  updateAdminMembership,
  type AdminMembershipEntry,
  type AdminMembershipFilters,
  type UserSearchResult,
} from "./admin-membership-api";

const LIMIT = 50;

// ── Main component ────────────────────────────────────────────────────────────

export function AdminMembershipDashboard() {
  const queryClient = useQueryClient();

  const [filters, setFilters] = useState<AdminMembershipFilters>({ page: 1 });
  const [draftSearch, setDraftSearch] = useState("");
  const [draftStatus, setDraftStatus] = useState<"" | "active" | "expired" | "cancelled">("");
  const [draftDateFrom, setDraftDateFrom] = useState("");
  const [draftDateTo, setDraftDateTo] = useState("");
  const [banner, setBanner] = useState<{ kind: "success" | "error"; text: string } | null>(null);

  const [createDialog, setCreateDialog] = useState(false);
  const [editTarget, setEditTarget] = useState<AdminMembershipEntry | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<AdminMembershipEntry | null>(null);

  const { data, isLoading, isError } = useQuery({
    queryKey: ["admin-memberships", filters],
    queryFn: () => fetchAdminMemberships(filters),
    staleTime: DEFAULT_STALE_TIME,
  });

  const entries = data?.data ?? [];
  const total = data?.meta.total ?? 0;
  const currentPage = filters.page ?? 1;
  const totalPages = Math.max(1, Math.ceil(total / LIMIT));

  function applySearch() {
    setFilters({
      search: draftSearch,
      status: draftStatus || undefined,
      dateFrom: draftDateFrom || undefined,
      dateTo: draftDateTo || undefined,
      page: 1,
    });
  }

  function applyStatus(value: "" | "active" | "expired" | "cancelled") {
    setDraftStatus(value);
    setFilters((prev) => ({ ...prev, status: value || undefined, page: 1 }));
  }

  async function refresh() {
    await queryClient.invalidateQueries({ queryKey: ["admin-memberships"] });
  }

  return (
    <section className="grid w-full gap-8 px-4 py-10">
      {/* ── Header ──────────────────────────────────────────────────────── */}
      <header className="grid gap-3">
        <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">
          Backoffice
        </p>
        <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
          <div>
            <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">
              Adhésions
            </h1>
            <p className="mt-3 max-w-2xl text-muted-foreground">
              Vue d&apos;ensemble des adhésions HelloAsso et manuelles.{total > 0 ? ` ${total} au total.` : ""}
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-3 self-start lg:self-auto">
            <Link
              className="inline-flex min-h-11 items-center gap-2 border border-border bg-surface px-4 text-sm font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
              href="/admin/adhesions/paiements-non-rattaches"
            >
              <AlertTriangle aria-hidden="true" className="size-4" />
              Paiements non rattachés
            </Link>
            <button
              className="inline-flex min-h-11 items-center gap-2 border border-accent bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
              type="button"
              onClick={() => { setBanner(null); setCreateDialog(true); }}
            >
              <Plus aria-hidden="true" className="size-4" />
              Créer une adhésion
            </button>
          </div>
        </div>
      </header>

      {/* ── Banner ───────────────────────────────────────────────────────── */}
      {banner && (
        <p
          className={banner.kind === "success"
            ? "border border-success/50 bg-surface p-3 text-sm text-success"
            : "border border-danger/50 bg-surface p-3 text-sm text-danger"}
          role={banner.kind === "success" ? "status" : "alert"}
        >
          {banner.text}
        </p>
      )}
      {isError && (
        <p className="border border-danger/50 bg-surface p-3 text-sm text-danger" role="alert">
          Impossible de charger les adhésions.
        </p>
      )}

      {/* ── Filters ──────────────────────────────────────────────────────── */}
      <div className="grid gap-4 border border-border bg-surface p-4">
        <div className="grid gap-4 md:grid-cols-[1fr_180px_auto]">
          <label className="grid gap-2 text-sm font-medium text-foreground">
            Recherche
            <span className="flex min-h-11 items-center gap-2 border border-border bg-background px-3 focus-within:border-accent">
              <Search aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
              <input
                className="min-w-0 flex-1 bg-transparent outline-none placeholder:text-muted-foreground"
                placeholder="Email ou nom affiché"
                type="search"
                value={draftSearch}
                onChange={(e) => setDraftSearch(e.target.value)}
                onKeyDown={(e) => { if (e.key === "Enter") applySearch(); }}
              />
            </span>
          </label>

          <label className="grid gap-2 text-sm font-medium text-foreground">
            Statut
            <select
              className="min-h-11 border border-border bg-background px-3 text-foreground outline-none focus:border-accent"
              value={draftStatus}
              onChange={(e) => applyStatus(e.target.value as "" | "active" | "expired" | "cancelled")}
            >
              <option value="">Tous</option>
              <option value="active">Actif</option>
              <option value="expired">Expiré</option>
              <option value="cancelled">Annulé</option>
            </select>
          </label>

          <div className="grid gap-2">
            <span aria-hidden="true" className="select-none text-sm font-medium text-transparent">&nbsp;</span>
            <button
              className="min-h-11 border border-border bg-background px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
              type="button"
              onClick={applySearch}
            >
              Rechercher
            </button>
          </div>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <label className="grid gap-2 text-sm font-medium text-foreground">
            Début de période
            <input
              className="min-h-11 border border-border bg-background px-3 text-foreground outline-none focus:border-accent"
              type="date"
              value={draftDateFrom}
              onChange={(e) => setDraftDateFrom(e.target.value)}
            />
          </label>
          <label className="grid gap-2 text-sm font-medium text-foreground">
            Fin de période
            <input
              className="min-h-11 border border-border bg-background px-3 text-foreground outline-none focus:border-accent"
              type="date"
              value={draftDateTo}
              onChange={(e) => setDraftDateTo(e.target.value)}
            />
          </label>
        </div>
      </div>

      {/* ── Table / States ───────────────────────────────────────────────── */}
      {isLoading ? (
        <LoadingSkeleton />
      ) : entries.length === 0 ? (
        <EmptyPanel icon={<Users aria-hidden="true" className="size-8 text-accent-text" />} title="Aucun résultat">
          Aucune adhésion ne correspond à cette recherche.
        </EmptyPanel>
      ) : (
        <>
          <div className="overflow-x-auto border border-border bg-surface">
            <table className="w-full min-w-[860px] border-collapse text-left text-sm">
              <thead className="border-b border-border text-muted-foreground">
                <tr>
                  <th className="px-4 py-3 font-medium">Adhérent</th>
                  <th className="px-4 py-3 font-medium">Statut</th>
                  <th className="px-4 py-3 font-medium">Début</th>
                  <th className="px-4 py-3 font-medium">Expiration</th>
                  <th className="px-4 py-3 font-medium">Source</th>
                  <th className="px-4 py-3 font-medium">Actions</th>
                </tr>
              </thead>
              <tbody>
                {entries.map((entry) => (
                  <tr className="border-b border-border last:border-b-0" key={entry.id}>
                    <td className="px-4 py-4">
                      <p className="font-semibold text-foreground">{entry.email}</p>
                      {entry.displayName && (
                        <p className="mt-0.5 text-xs text-muted-foreground">{entry.displayName}</p>
                      )}
                    </td>
                    <td className="px-4 py-4">
                      <StatusBadge status={entry.status} />
                    </td>
                    <td className="px-4 py-4 text-muted-foreground">
                      {entry.startedAt ? <time dateTime={entry.startedAt}>{formatDate(entry.startedAt)}</time> : "-"}
                    </td>
                    <td className="px-4 py-4 text-muted-foreground">
                      {entry.expiresAt ? <time dateTime={entry.expiresAt}>{formatDate(entry.expiresAt)}</time> : "-"}
                    </td>
                    <td className="px-4 py-4">
                      <SourceBadge source={entry.source} />
                    </td>
                    <td className="px-4 py-4">
                      <div className="flex items-center gap-2">
                        <button
                          aria-label={`Modifier l'adhésion de ${entry.email}`}
                          className="inline-flex size-8 items-center justify-center border border-border text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
                          type="button"
                          onClick={() => { setBanner(null); setEditTarget(entry); }}
                        >
                          <Pencil aria-hidden="true" className="size-3.5" />
                        </button>
                        <button
                          aria-label={`Annuler l'adhésion de ${entry.email}`}
                          className="inline-flex size-8 items-center justify-center border border-border text-muted-foreground transition-colors hover:border-danger hover:text-danger"
                          type="button"
                          onClick={() => { setBanner(null); setDeleteTarget(entry); }}
                        >
                          <Trash2 aria-hidden="true" className="size-3.5" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {totalPages > 1 && (
            <div className="flex items-center justify-between gap-4 text-sm text-muted-foreground">
              <span>Page {currentPage} / {totalPages}</span>
              <div className="flex gap-2">
                <button
                  className="inline-flex size-9 items-center justify-center border border-border text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-40"
                  disabled={currentPage <= 1}
                  type="button"
                  onClick={() => setFilters((p) => ({ ...p, page: currentPage - 1 }))}
                >
                  <ChevronLeft aria-hidden="true" className="size-4" />
                  <span className="sr-only">Page précédente</span>
                </button>
                <button
                  className="inline-flex size-9 items-center justify-center border border-border text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-40"
                  disabled={currentPage >= totalPages}
                  type="button"
                  onClick={() => setFilters((p) => ({ ...p, page: currentPage + 1 }))}
                >
                  <ChevronRight aria-hidden="true" className="size-4" />
                  <span className="sr-only">Page suivante</span>
                </button>
              </div>
            </div>
          )}
        </>
      )}

      {/* ── Dialogs ──────────────────────────────────────────────────────── */}
      {createDialog && (
        <CreateDialog
          onClose={() => setCreateDialog(false)}
          onSuccess={async (email) => {
            setCreateDialog(false);
            setBanner({ kind: "success", text: `Adhésion créée pour ${email}.` });
            await refresh();
          }}
        />
      )}

      {editTarget && (
        <EditDialog
          entry={editTarget}
          onClose={() => setEditTarget(null)}
          onSuccess={async () => {
            setEditTarget(null);
            setBanner({ kind: "success", text: "Adhésion mise à jour." });
            await refresh();
          }}
        />
      )}

      {deleteTarget && (
        <DeleteDialog
          entry={deleteTarget}
          onClose={() => setDeleteTarget(null)}
          onSuccess={async () => {
            setDeleteTarget(null);
            setBanner({ kind: "success", text: `Adhésion de ${deleteTarget.email} annulée.` });
            await refresh();
          }}
        />
      )}
    </section>
  );
}

// ── CreateDialog ──────────────────────────────────────────────────────────────

function CreateDialog({
  onClose,
  onSuccess,
}: {
  onClose: () => void;
  onSuccess: (email: string) => Promise<void>;
}) {
  const [selectedUser, setSelectedUser] = useState<UserSearchResult | null>(null);
  const [startedAt, setStartedAt] = useState(toDateInput(new Date().toISOString()));
  const [expiresAt, setExpiresAt] = useState("");
  const [adminNote, setAdminNote] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const { data: existingMembership } = useQuery({
    queryKey: ["admin-membership-active-check", selectedUser?.id],
    queryFn: () => fetchUserActiveMembership(selectedUser!.id),
    enabled: selectedUser !== null,
    staleTime: 30_000,
  });

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!selectedUser) { setError("Sélectionne un utilisateur."); return; }
    setError(null);
    setSubmitting(true);
    const result = await createAdminMembership(
      selectedUser.id,
      adminNote.trim() || undefined,
      startedAt || undefined,
      expiresAt || undefined,
    );
    setSubmitting(false);
    if (!result) { setError("Impossible de créer l'adhésion."); return; }
    await onSuccess(selectedUser.email);
  }

  return (
    <Dialog title="Créer une adhésion manuelle" onClose={onClose}>
      <form className="grid gap-4" onSubmit={(e) => { void handleSubmit(e); }}>
        <div className="grid gap-2">
          <p className="text-sm font-medium text-foreground">Utilisateur *</p>
          <UserCombobox selected={selectedUser} onSelect={setSelectedUser} />
        </div>

        {existingMembership && (
          <p className="border border-accent-warm/50 bg-surface p-3 text-sm text-accent-warm" role="alert">
            Adhésion active du{" "}
            <strong>{existingMembership.startedAt ? formatDate(existingMembership.startedAt) : "-"}</strong>
            {existingMembership.expiresAt && (
              <> au <strong>{formatDate(existingMembership.expiresAt)}</strong></>
            )}
            {" "}- elle sera expirée à la création.
          </p>
        )}

        <label className="grid gap-2 text-sm font-medium text-foreground">
          Date de début *
          <input
            className="min-h-11 border border-border bg-surface px-3 text-foreground outline-none focus:border-accent"
            required
            type="date"
            value={startedAt}
            onChange={(e) => setStartedAt(e.target.value)}
          />
        </label>

        <label className="grid gap-2 text-sm font-medium text-foreground">
          Date d&apos;expiration
          <input
            className="min-h-11 border border-border bg-surface px-3 text-foreground outline-none focus:border-accent"
            type="date"
            value={expiresAt}
            onChange={(e) => setExpiresAt(e.target.value)}
          />
          <span className="text-xs text-muted-foreground">Laissez vide pour calculer automatiquement (début + 12 mois).</span>
        </label>

        <label className="grid gap-2 text-sm font-medium text-foreground">
          Note admin
          <textarea
            className="border border-border bg-surface px-3 py-2 text-sm text-foreground outline-none focus:border-accent"
            placeholder="Optionnel - paiement espèces, virement…"
            rows={3}
            value={adminNote}
            onChange={(e) => setAdminNote(e.target.value)}
          />
        </label>

        {error && <ErrorBanner>{error}</ErrorBanner>}

        <DialogActions
          cancelLabel="Annuler"
          confirmLabel={submitting ? "Création…" : "Créer"}
          disabled={submitting || !selectedUser}
          onCancel={onClose}
        />
      </form>
    </Dialog>
  );
}

// ── EditDialog ────────────────────────────────────────────────────────────────

function EditDialog({
  entry,
  onClose,
  onSuccess,
}: {
  entry: AdminMembershipEntry;
  onClose: () => void;
  onSuccess: () => Promise<void>;
}) {
  const [startedAt, setStartedAt] = useState(toDateInput(entry.startedAt));
  const [expiresAt, setExpiresAt] = useState(toDateInput(entry.expiresAt));
  const [adminNote, setAdminNote] = useState(entry.adminNote ?? "");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    const result = await updateAdminMembership(entry.id, {
      startedAt,
      expiresAt: expiresAt || undefined,
      adminNote: adminNote.trim() || null,
    });
    setSubmitting(false);
    if (!result) { setError("Impossible de mettre à jour l'adhésion."); return; }
    await onSuccess();
  }

  return (
    <Dialog title={`Modifier l'adhésion - ${entry.email}`} onClose={onClose}>
      <form className="grid gap-4" onSubmit={(e) => { void handleSubmit(e); }}>
        <label className="grid gap-2 text-sm font-medium text-foreground">
          Date de début *
          <input
            className="min-h-11 border border-border bg-surface px-3 text-foreground outline-none focus:border-accent"
            required
            type="date"
            value={startedAt}
            onChange={(e) => setStartedAt(e.target.value)}
          />
        </label>

        <label className="grid gap-2 text-sm font-medium text-foreground">
          Date d&apos;expiration
          <input
            className="min-h-11 border border-border bg-surface px-3 text-foreground outline-none focus:border-accent"
            type="date"
            value={expiresAt}
            onChange={(e) => setExpiresAt(e.target.value)}
          />
          <span className="text-xs text-muted-foreground">Laissez vide pour calculer automatiquement (début + 12 mois).</span>
        </label>

        <label className="grid gap-2 text-sm font-medium text-foreground">
          Note admin
          <textarea
            className="border border-border bg-surface px-3 py-2 text-sm text-foreground outline-none focus:border-accent"
            placeholder="Optionnel"
            rows={3}
            value={adminNote}
            onChange={(e) => setAdminNote(e.target.value)}
          />
        </label>

        {error && <ErrorBanner>{error}</ErrorBanner>}

        <DialogActions
          cancelLabel="Annuler"
          confirmLabel={submitting ? "Enregistrement…" : "Enregistrer"}
          disabled={submitting}
          onCancel={onClose}
        />
      </form>
    </Dialog>
  );
}

// ── DeleteDialog ──────────────────────────────────────────────────────────────

function DeleteDialog({
  entry,
  onClose,
  onSuccess,
}: {
  entry: AdminMembershipEntry;
  onClose: () => void;
  onSuccess: () => Promise<void>;
}) {
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleDelete() {
    setError(null);
    setSubmitting(true);
    const ok = await deleteAdminMembership(entry.id);
    setSubmitting(false);
    if (!ok) { setError("Impossible de supprimer l'adhésion."); return; }
    await onSuccess();
  }

  return (
    <Dialog title="Annuler l'adhésion" onClose={onClose}>
      <div className="grid gap-4">
        <p className="text-sm text-muted-foreground">
          Confirmer l&apos;annulation de l&apos;adhésion de{" "}
          <strong className="text-foreground">{entry.email}</strong>
          {entry.status === "active" && (
            <> - cette adhésion est <strong className="text-danger">active</strong>, l&apos;utilisateur perdra son accès membre.</>
          )}
        </p>

        {error && <ErrorBanner>{error}</ErrorBanner>}

        <DialogActions
          cancelLabel="Retour"
          confirmClassName="bg-danger hover:bg-danger/80"
          confirmLabel={submitting ? "Annulation…" : "Annuler l'adhésion"}
          disabled={submitting}
          onCancel={onClose}
          onConfirm={() => { void handleDelete(); }}
        />
      </div>
    </Dialog>
  );
}

// ── UserCombobox ──────────────────────────────────────────────────────────────

function UserCombobox({
  selected,
  onSelect,
}: {
  selected: UserSearchResult | null;
  onSelect: (user: UserSearchResult | null) => void;
}) {
  const inputId = useId();
  const [query, setQuery] = useState("");
  const [debouncedQuery, setDebouncedQuery] = useState("");
  const [open, setOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    const t = setTimeout(() => setDebouncedQuery(query), 250);
    return () => clearTimeout(t);
  }, [query]);

  useEffect(() => {
    function onPointerDown(e: PointerEvent) {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener("pointerdown", onPointerDown);
    return () => document.removeEventListener("pointerdown", onPointerDown);
  }, []);

  const { data: results = [], isFetching } = useQuery({
    queryKey: ["admin-user-search", debouncedQuery],
    queryFn: () => searchUsersForMembership(debouncedQuery),
    enabled: debouncedQuery.trim().length >= 2,
    staleTime: 60_000,
  });

  if (selected) {
    return (
      <div className="flex min-h-11 items-center justify-between border border-accent/60 bg-surface px-3">
        <div>
          <p className="text-sm font-semibold text-foreground">{selected.email}</p>
          {selected.displayName && (
            <p className="text-xs text-muted-foreground">{selected.displayName}</p>
          )}
        </div>
        <button
          aria-label="Effacer la sélection"
          className="ml-2 shrink-0 text-muted-foreground transition-colors hover:text-foreground"
          type="button"
          onClick={() => { onSelect(null); setQuery(""); setTimeout(() => inputRef.current?.focus(), 0); }}
        >
          <X aria-hidden="true" className="size-4" />
        </button>
      </div>
    );
  }

  const showDropdown = open && query.trim().length >= 2;

  return (
    <div ref={containerRef} className="relative">
      <span className="flex min-h-11 items-center gap-2 border border-border bg-background px-3 focus-within:border-accent">
        <Search aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
        <input
          ref={inputRef}
          aria-autocomplete="list"
          aria-controls={showDropdown ? `${inputId}-listbox` : undefined}
          aria-expanded={showDropdown}
          autoComplete="off"
          className="min-w-0 flex-1 bg-transparent outline-none placeholder:text-muted-foreground"
          id={inputId}
          placeholder="Rechercher par email ou pseudo…"
          role="combobox"
          type="search"
          value={query}
          onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
          onFocus={() => setOpen(true)}
        />
      </span>

      {showDropdown && (
        <div
          className="absolute top-full z-50 mt-px w-full border border-border bg-background shadow-lg"
          id={`${inputId}-listbox`}
          role="listbox"
        >
          {isFetching && <p className="px-3 py-2.5 text-sm text-muted-foreground">Recherche…</p>}
          {!isFetching && results.length === 0 && (
            <p className="px-3 py-2.5 text-sm text-muted-foreground">Aucun utilisateur trouvé.</p>
          )}
          {!isFetching && results.map((user) => (
            <button
              aria-selected={false}
              className="flex w-full flex-col px-3 py-2.5 text-left transition-colors hover:bg-surface focus:bg-surface focus:outline-none"
              key={user.id}
              role="option"
              type="button"
              onClick={() => { onSelect(user); setQuery(""); setOpen(false); }}
            >
              <span className="text-sm font-medium text-foreground">{user.email}</span>
              {user.displayName && <span className="text-xs text-muted-foreground">{user.displayName}</span>}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

// ── Shared dialog primitives ──────────────────────────────────────────────────

function Dialog({
  children,
  onClose,
  title,
}: {
  children: ReactNode;
  onClose: () => void;
  title: string;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div
        aria-modal="true"
        className="grid w-full max-w-md gap-6 border border-border bg-background p-6"
        role="dialog"
      >
        <div className="flex items-start justify-between gap-4">
          <h2 className="font-heading text-xl font-semibold text-foreground">{title}</h2>
          <button
            aria-label="Fermer"
            className="mt-0.5 shrink-0 text-muted-foreground transition-colors hover:text-foreground"
            type="button"
            onClick={onClose}
          >
            <X aria-hidden="true" className="size-5" />
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}

function DialogActions({
  cancelLabel,
  confirmClassName = "bg-accent hover:bg-accent-hover",
  confirmLabel,
  disabled,
  onCancel,
  onConfirm,
}: {
  cancelLabel: string;
  confirmClassName?: string;
  confirmLabel: string;
  disabled: boolean;
  onCancel: () => void;
  onConfirm?: () => void;
}) {
  return (
    <div className="flex justify-end gap-3">
      <button
        className="inline-flex min-h-10 items-center justify-center border border-border px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:opacity-50"
        disabled={disabled}
        type="button"
        onClick={onCancel}
      >
        {cancelLabel}
      </button>
      <button
        className={`inline-flex min-h-10 items-center justify-center px-4 text-sm font-semibold text-white transition-colors disabled:cursor-not-allowed disabled:opacity-60 ${confirmClassName}`}
        disabled={disabled}
        type={onConfirm ? "button" : "submit"}
        onClick={onConfirm}
      >
        {confirmLabel}
      </button>
    </div>
  );
}

function ErrorBanner({ children }: { children: ReactNode }) {
  return (
    <p className="border border-danger/50 bg-surface p-3 text-sm text-danger" role="alert">
      {children}
    </p>
  );
}

// ── Small display components ──────────────────────────────────────────────────

function StatusBadge({ status }: { status: "active" | "expired" | "cancelled" }) {
  if (status === "active") {
    return (
      <span className="inline-flex items-center border border-success/50 px-2 py-0.5 text-xs font-semibold text-success">
        Actif
      </span>
    );
  }
  if (status === "cancelled") {
    return (
      <span className="inline-flex items-center border border-danger/50 px-2 py-0.5 text-xs font-semibold text-danger">
        Annulé
      </span>
    );
  }
  return (
    <span className="inline-flex items-center border border-border px-2 py-0.5 text-xs font-semibold text-muted-foreground">
      Expiré
    </span>
  );
}

function SourceBadge({ source }: { source: string }) {
  if (source === "helloasso") {
    return (
      <span className="inline-flex items-center border border-accent/50 px-2 py-0.5 text-xs font-semibold text-accent-text">
        HelloAsso
      </span>
    );
  }
  return (
    <span className="inline-flex items-center border border-border px-2 py-0.5 text-xs font-semibold text-muted-foreground">
      Manuel
    </span>
  );
}

function LoadingSkeleton() {
  return (
    <div aria-busy="true" className="grid gap-3 border border-border bg-surface p-5">
      <div className="h-4 w-48 bg-surface-2" />
      <div className="h-12 bg-surface-2" />
      <div className="h-12 bg-surface-2" />
      <div className="h-12 bg-surface-2" />
    </div>
  );
}

function EmptyPanel({
  children,
  icon,
  title,
}: Readonly<{ children: ReactNode; icon: ReactNode; title: string }>) {
  return (
    <div className="grid justify-items-center gap-3 border border-border bg-surface p-8 text-center">
      {icon}
      <h2 className="font-heading text-2xl font-semibold text-foreground">{title}</h2>
      <p className="max-w-md text-sm leading-6 text-muted-foreground">{children}</p>
    </div>
  );
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function toDateInput(iso: string | null): string {
  if (!iso) return "";
  try {
    return new Date(iso).toISOString().slice(0, 10);
  } catch {
    return "";
  }
}

function formatDate(iso: string): string {
  try {
    return new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "short", year: "numeric" }).format(new Date(iso));
  } catch {
    return iso;
  }
}
