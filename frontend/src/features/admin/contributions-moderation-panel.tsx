"use client";

import { useCallback } from "react";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { Loader2 } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { fetchContributionQueue, type ContributionFilters, type ContributionItem } from "./admin-game-contributions-api";
import {
  clearedContributionFilters,
  CONTRIBUTION_SORT_OPTIONS,
  CONTRIBUTION_STATUS_OPTIONS,
  CONTRIBUTION_TARGET_OPTIONS,
  contributionChips,
  contributionFiltersActive,
  contributionFiltersFromParams,
  contributionFiltersToParams,
} from "./moderation-filters";
import { FilterSelect, ModerationToolbar } from "./moderation-toolbar";

export const QUERY_PREFIX = ["admin-game-contributions"] as const;
const STALE_TIME = 15_000;

/**
 * The tutorial contributions tab. Story 39.12: same toolbar as the reports, its view in the page address. Story
 * 39.16: a compact list, one line per contribution; each opens on its own page.
 */
export function ContributionsModerationPanel({ params, onParams }: { params: URLSearchParams; onParams: (next: URLSearchParams) => void }) {
  const filters = contributionFiltersFromParams(params);

  const { data, isLoading, isError, isFetching } = useQuery({
    queryKey: [...QUERY_PREFIX, "list", filters],
    queryFn: () => fetchContributionQueue(filters),
    staleTime: STALE_TIME,
  });

  const update = (next: ContributionFilters) => onParams(contributionFiltersToParams(next));
  const onSearch = useCallback(
    (search: string) => onParams(contributionFiltersToParams({ ...contributionFiltersFromParams(params), search })),
    [onParams, params],
  );

  const chips = contributionChips(filters);
  const shown = data?.items.length ?? 0;

  return (
    <div className="grid gap-4">
      <ModerationToolbar
        active={contributionFiltersActive(filters)}
        filters={
          <>
            <FilterSelect
              defaultValue="pending"
              label="Statut"
              onChange={(status) => update({ ...filters, status })}
              options={CONTRIBUTION_STATUS_OPTIONS}
              value={filters.status}
            />
            <FilterSelect defaultValue="any" label="Cible" onChange={(target) => update({ ...filters, target })} options={CONTRIBUTION_TARGET_OPTIONS} value={filters.target} />
          </>
        }
        onReset={() => update(clearedContributionFilters(filters))}
        onSearch={onSearch}
        onSort={(sort) => update({ ...filters, sort })}
        resultLabel={data === undefined ? null : `${shown} contribution${shown > 1 ? "s" : ""}`}
        search={filters.search}
        searchPlaceholder="Jeu, nom proposé, auteur ou message…"
        sort={filters.sort}
        sortOptions={CONTRIBUTION_SORT_OPTIONS}
      />

      {isLoading ? (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement…
        </p>
      ) : isError || data === undefined ? (
        <p className="text-sm text-muted-foreground">Impossible de charger les contributions.</p>
      ) : data.items.length === 0 ? (
        <div className="grid justify-items-center gap-3 rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
          <p>{chips.length > 0 ? "Aucune contribution ne correspond à ces filtres." : "Aucune contribution ici. 🎉"}</p>
          {chips.length > 0 ? (
            <button className={buttonVariants({ variant: "secondary" })} onClick={() => update(clearedContributionFilters(filters))} type="button">
              Effacer les filtres
            </button>
          ) : null}
        </div>
      ) : (
        <ul aria-busy={isFetching} className="divide-y divide-border overflow-hidden rounded-lg border border-border bg-surface" role="list">
          {data.items.map((item) => (
            <li key={item.id}>
              <ContributionRow item={item} listQuery={params.toString()} />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

const STATUS_PILL: Record<string, { label: string; tone: string }> = {
  pending: { label: "En attente", tone: "border-accent-warm/40 bg-accent-warm/10 text-accent-warm" },
  approved: { label: "Approuvée", tone: "border-success/40 bg-success/10 text-success" },
  rejected: { label: "Rejetée", tone: "border-border text-muted-foreground" },
};

export function contributionStatus(status: string): { label: string; tone: string } {
  return STATUS_PILL[status] ?? { label: status, tone: "border-border text-muted-foreground" };
}

/** A contribution's page, keeping the list's view so « Retour » finds it as it was (story 39.16). */
export function contributionHref(id: string, listQuery: string): string {
  return listQuery === "" ? `/admin/moderation/contributions/${id}` : `/admin/moderation/contributions/${id}?liste=${encodeURIComponent(listQuery)}`;
}

/**
 * One line of the queue (story 39.16): what, by whom, when, its state and how big - the comparison and the
 * decision live on the contribution's own page.
 */
export function ContributionRow({ item, listQuery }: { item: ContributionItem; listQuery: string }) {
  const status = contributionStatus(item.status);
  const steps = item.proposedSteps.length;

  return (
    <Link className="grid gap-1 px-4 py-3 transition-colors hover:bg-surface-2" href={contributionHref(item.id, listQuery)}>
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
        <span className="min-w-0 flex-1 truncate font-semibold text-foreground">{item.target || "Sans nom"}</span>
        {item.gameSlug === null ? (
          <span className="rounded border border-warning/50 bg-warning/10 px-1.5 text-xs font-semibold text-warning">Jeu non listé</span>
        ) : null}
        <span className={`rounded-full border px-2 py-0.5 text-xs font-medium ${status.tone}`}>{status.label}</span>
      </div>
      <p className="text-xs text-muted-foreground">
        {item.authorName || "Auteur inconnu"} · {listDate.format(new Date(item.createdAt))} · {steps} étape{steps > 1 ? "s" : ""} proposée{steps > 1 ? "s" : ""}
      </p>
      {item.message ? <p className="truncate text-sm text-muted-foreground">« {item.message} »</p> : null}
    </Link>
  );
}

const listDate = new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium", timeStyle: "short", timeZone: "Europe/Paris" });

