"use client";

import { useEffect, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader2, Search } from "lucide-react";

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
        <p className="rounded-lg border border-border bg-surface px-4 py-8 text-center text-sm text-muted-foreground">
          {isDefault ? "Aucune contribution en attente. 🎉" : "Aucune contribution ne correspond à ces filtres."}
        </p>
      ) : (
        <ul aria-busy={isFetching} className="grid gap-4" role="list">
          {data.items.map((item) => (
            <li key={item.id}>
              <ContributionCard
                busy={busyId === item.id}
                item={item}
                onApprove={() => void run(item.id, () => approveContribution(item.id))}
                onReject={(reason) => void run(item.id, () => rejectContribution(item.id, reason))}
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
  onApprove: () => void;
  onReject: (reason: string) => void;
}) {
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState("");

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
        <p className="whitespace-pre-line rounded border border-border bg-background px-3 py-2 text-sm text-muted-foreground">
          {item.message}
        </p>
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

      <p className="text-xs text-muted-foreground">
        Approuver <strong className="text-foreground">remplace l&apos;intégralité</strong> du tutoriel par la
        version proposée.
      </p>

      {rejecting ? (
        <div className="grid gap-2">
          <textarea
            aria-label="Raison du refus"
            className="min-h-16 w-full rounded border border-border bg-background px-3 py-2 text-sm outline-none focus:border-accent"
            onChange={(e) => setReason(e.target.value)}
            placeholder="Raison du refus (envoyée à l'auteur)"
            value={reason}
          />
          <div className="flex flex-wrap items-center gap-3">
            <button
              className="inline-flex min-h-9 items-center justify-center rounded bg-danger px-4 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:opacity-50"
              disabled={busy || reason.trim() === ""}
              onClick={() => onReject(reason)}
              type="button"
            >
              Confirmer le refus
            </button>
            <button className="text-sm text-muted-foreground hover:text-foreground" onClick={() => setRejecting(false)} type="button">
              Annuler
            </button>
          </div>
        </div>
      ) : (
        <div className="flex flex-wrap items-center gap-3">
          <button
            className="inline-flex min-h-9 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
            disabled={busy}
            onClick={onApprove}
            type="button"
          >
            {busy ? "…" : "Approuver"}
          </button>
          <button
            className="inline-flex min-h-9 items-center justify-center rounded border border-border px-4 text-sm font-semibold text-foreground transition-colors hover:border-danger disabled:opacity-50"
            disabled={busy}
            onClick={() => setRejecting(true)}
            type="button"
          >
            Rejeter
          </button>
        </div>
      )}
    </article>
  );
}
