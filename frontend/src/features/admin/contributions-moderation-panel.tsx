"use client";

import { useCallback, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader2 } from "lucide-react";

import { Markdown } from "@/components/markdown/markdown";
import { buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import { InstallStepsView } from "@/features/games/install-steps-view";
import {
  approveContribution,
  fetchContributionQueue,
  rejectContribution,
  type ContributionFilters,
  type ContributionItem,
} from "./admin-game-contributions-api";
import {
  clearedContributionFilters,
  CONTRIBUTION_SORT_OPTIONS,
  CONTRIBUTION_STATUS_OPTIONS,
  CONTRIBUTION_TARGET_OPTIONS,
  contributionChips,
  contributionFiltersFromParams,
  contributionFiltersToParams,
} from "./moderation-filters";
import { FilterSelect, ModerationToolbar, SegmentedControl } from "./moderation-toolbar";

const QUERY_PREFIX = ["admin-game-contributions"] as const;
const STALE_TIME = 15_000;

/**
 * The tutorial contributions tab. Story 39.12: same toolbar as the reports, its view in the page address.
 */
export function ContributionsModerationPanel({ params, onParams }: { params: URLSearchParams; onParams: (next: URLSearchParams) => void }) {
  const queryClient = useQueryClient();
  const filters = contributionFiltersFromParams(params);
  const [busyId, setBusyId] = useState<string | null>(null);

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

  async function run(id: string, action: () => Promise<boolean>): Promise<void> {
    setBusyId(id);
    await action();
    await queryClient.invalidateQueries({ queryKey: QUERY_PREFIX });
    setBusyId(null);
  }

  const chips = contributionChips(filters);
  const shown = data?.items.length ?? 0;

  return (
    <div className="grid gap-4">
      <SegmentedControl label="Statut des contributions" onChange={(status) => update({ ...filters, status })} options={CONTRIBUTION_STATUS_OPTIONS} value={filters.status} />

      <ModerationToolbar
        active={chips.length > 0}
        filters={
          <FilterSelect defaultValue="any" label="Cible" onChange={(target) => update({ ...filters, target })} options={CONTRIBUTION_TARGET_OPTIONS} value={filters.target} />
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
