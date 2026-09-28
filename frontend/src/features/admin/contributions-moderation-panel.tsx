"use client";

import { useEffect, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader2, Search } from "lucide-react";

import { Markdown } from "@/components/markdown/markdown";
import { buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import { InstallStepsView } from "@/features/games/install-steps-view";
import {
  approveContribution,
  DEFAULT_CONTRIBUTION_FILTERS,
  fetchContributionQueue,
  rejectContribution,
  type ContributionFilters,
  type ContributionItem,
  type ContributionSort,
  type ContributionStatus,
  type ContributionTarget,
} from "./admin-game-contributions-api";

const QUERY_PREFIX = ["admin-game-contributions"] as const;
const STALE_TIME = 15_000;
const SEARCH_DEBOUNCE_MS = 300;

const STATUS_OPTIONS: { value: ContributionStatus; label: string }[] = [
  { value: "pending", label: "En attente" },
  { value: "approved", label: "Approuvées" },
  { value: "rejected", label: "Rejetées" },
  { value: "all", label: "Toutes" },
];

const TARGET_OPTIONS: { value: ContributionTarget; label: string }[] = [
  { value: "any", label: "Toutes cibles" },
  { value: "listed", label: "Jeux listés" },
  { value: "unlisted", label: "Jeux non listés" },
];

const SORT_OPTIONS: { value: ContributionSort; label: string }[] = [
  { value: "recent", label: "Plus récentes" },
  { value: "oldest", label: "Plus anciennes" },
];

export function ContributionsModerationPanel() {
  const queryClient = useQueryClient();
  const [status, setStatus] = useState<ContributionStatus>("pending");
  const [target, setTarget] = useState<ContributionTarget>("any");
  const [sort, setSort] = useState<ContributionSort>("recent");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [busyId, setBusyId] = useState<string | null>(null);

  useEffect(() => {
    const handle = setTimeout(() => setSearch(searchInput.trim()), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(handle);
  }, [searchInput]);

  const filters: ContributionFilters = { status, target, sort, search };
  const { data, isLoading, isError, isFetching } = useQuery({
    queryKey: [...QUERY_PREFIX, "list", filters],
    queryFn: () => fetchContributionQueue(filters),
    staleTime: STALE_TIME,
  });

  async function run(id: string, action: () => Promise<boolean>): Promise<void> {
    setBusyId(id);
    await action();
    await queryClient.invalidateQueries({ queryKey: QUERY_PREFIX });
    setBusyId(null);
  }

  const isDefault =
    status === DEFAULT_CONTRIBUTION_FILTERS.status &&
    target === DEFAULT_CONTRIBUTION_FILTERS.target &&
    search === "";

  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap items-center gap-2" role="tablist" aria-label="Statut des contributions">
        {STATUS_OPTIONS.map((option) => (
          <button
            aria-selected={status === option.value}
            className={`min-h-9 rounded-full border px-3 text-sm font-semibold transition-colors ${
              status === option.value
                ? "border-accent bg-accent/15 text-foreground"
                : "border-border text-muted-foreground hover:border-accent hover:text-foreground"
            }`}
            key={option.value}
            onClick={() => setStatus(option.value)}
            role="tab"
            type="button"
          >
            {option.label}
          </button>
        ))}
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <label className="grid gap-1 text-xs font-medium text-muted-foreground">
          Cible
          <FilterSelect
            onChange={(value) => setTarget(value as ContributionTarget)}
            options={TARGET_OPTIONS}
            value={target}
          />
        </label>
        <label className="grid gap-1 text-xs font-medium text-muted-foreground">
          Tri
          <FilterSelect onChange={(value) => setSort(value as ContributionSort)} options={SORT_OPTIONS} value={sort} />
        </label>
        <label className="grid flex-1 gap-1 text-xs font-medium text-muted-foreground">
          Recherche
          <span className="relative">
            <Search aria-hidden className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <input
              className="min-h-9 w-full rounded-lg border border-border bg-background pl-9 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none"
              onChange={(event) => setSearchInput(event.target.value)}
              placeholder="Jeu, nom proposé, auteur ou message…"
              type="search"
              value={searchInput}
            />
          </span>
        </label>
      </div>

      {isLoading ? (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement…
        </p>
      ) : isError || data === undefined ? (
        <p className="text-sm text-muted-foreground">Impossible de charger les contributions.</p>
      ) : data.items.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
          {isDefault ? "Aucune contribution en attente. 🎉" : "Aucune contribution ne correspond à ces filtres."}
        </p>
      ) : (
        <ul aria-busy={isFetching} className="grid gap-4" role="list">
          {data.items.map((item) => (
            <li key={item.id}>
              <ContributionCard
                busy={busyId === item.id}
                item={item}
                onApprove={() => run(item.id, () => approveContribution(item.id))}
                onReject={(reason) => run(item.id, () => rejectContribution(item.id, reason))}
              />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function FilterSelect({
  value,
  options,
  onChange,
}: {
  value: string;
  options: { value: string; label: string }[];
  onChange: (value: string) => void;
}) {
  return (
    <select
      className="min-h-9 rounded-lg border border-border bg-background px-2 text-sm text-foreground focus:border-accent focus:outline-none"
      onChange={(event) => onChange(event.target.value)}
      value={value}
    >
      {options.map((option) => (
        <option key={option.value} value={option.value}>
          {option.label}
        </option>
      ))}
    </select>
  );
}

function ContributionCard({
  item,
  busy,
  onApprove,
  onReject,
}: {
  item: ContributionItem;
  busy: boolean;
  onApprove: () => Promise<void>;
  onReject: (reason: string) => Promise<void>;
}) {
  // Story 39.11: approving replaces the whole tutorial, so it is confirmed; rejecting asks its reason in a window.
  const [confirming, setConfirming] = useState(false);
  const [rejecting, setRejecting] = useState(false);

  return (
    <article className="grid gap-4 rounded-lg border border-border bg-surface p-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 className="font-heading font-semibold text-foreground">{item.target}</h3>
          <p className="text-xs text-muted-foreground">
            par {item.authorName || "inconnu"} · {new Date(item.createdAt).toLocaleString("fr-FR")}
          </p>
        </div>
        {item.gameSlug === null ? (
          <span className="rounded border border-warning/50 bg-warning/10 px-2 py-0.5 text-xs font-semibold text-warning">
            Jeu non listé
          </span>
        ) : null}
      </div>

      {item.message ? (
        <Markdown className="border-l-2 border-border pl-3 text-sm text-muted-foreground" untrusted>
          {item.message}
        </Markdown>
      ) : null}

      <div className="grid gap-4 lg:grid-cols-2">
        {item.gameSlug !== null ? (
          <div className="grid gap-2">
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Actuel</p>
            {item.currentSteps.length > 0 ? (
              <InstallStepsView steps={item.currentSteps} />
            ) : (
              <p className="text-sm text-muted-foreground">Aucune étape actuelle.</p>
            )}
          </div>
        ) : null}
        <div className="grid gap-2">
          <p className="text-xs font-semibold uppercase tracking-wide text-accent-text">Proposé</p>
          <InstallStepsView steps={item.proposedSteps} />
        </div>
      </div>

      <div className="flex flex-wrap justify-end gap-2">
        <button className={buttonVariants({ variant: "secondary" })} disabled={busy} onClick={() => setRejecting(true)} type="button">
          Rejeter
        </button>
        <button className={buttonVariants({ variant: "primary" })} disabled={busy} onClick={() => setConfirming(true)} type="button">
          Approuver
        </button>
      </div>

      <ConfirmDialog
        confirmLabel="Approuver"
        description={
          <>
            La version proposée <strong className="text-foreground">remplace l&apos;intégralité</strong> du tutoriel de{" "}
            {item.target}.
          </>
        }
        onConfirm={() => void onApprove().then(() => setConfirming(false))}
        onOpenChange={setConfirming}
        open={confirming}
        pending={busy}
        title="Approuver cette contribution ?"
      />

      {rejecting ? <RejectDialog busy={busy} onClose={() => setRejecting(false)} onReject={onReject} target={item.target} /> : null}
    </article>
  );
}

function RejectDialog({
  target,
  busy,
  onReject,
  onClose,
}: {
  target: string;
  busy: boolean;
  onReject: (reason: string) => Promise<void>;
  onClose: () => void;
}) {
  const [reason, setReason] = useState("");

  return (
    <Dialog
      description="La raison est envoyée à l'auteur de la contribution."
      onOpenChange={(open) => (open ? undefined : onClose())}
      open
      title={`Rejeter la contribution sur ${target}`}
    >
      <DialogBody>
        <label className="grid gap-1 text-sm">
          <span className="font-medium text-foreground">Raison du refus (obligatoire)</span>
          <textarea
            className="min-h-24 rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-accent focus:outline-none"
            onChange={(event) => setReason(event.target.value)}
            value={reason}
          />
        </label>
      </DialogBody>
      <DialogFooter>
        <button className={buttonVariants({ variant: "ghost" })} onClick={onClose} type="button">
          Annuler
        </button>
        <button
          className={buttonVariants({ variant: "danger" })}
          disabled={busy || reason.trim() === ""}
          onClick={() => void onReject(reason).then(onClose)}
          type="button"
        >
          {busy ? <Loader2 aria-hidden className="size-4 animate-spin" /> : null}
          Rejeter
        </button>
      </DialogFooter>
    </Dialog>
  );
}
