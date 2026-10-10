"use client";

import Link from "next/link";
import { useCallback, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { EyeOff, Eye, Loader2, ShieldCheck } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

import { FlaggedAccounts } from "./flagged-accounts";

import {
  CATEGORY_LABELS,
  fetchModerationQueue,
  hideModerationComment,
  PROBLEM_LABELS,
  resolveModerationReport,
  restoreModerationComment,
  type ModerationReport,
  type ReportFilters,
} from "./admin-moderation-api";
import {
  clearedReportFilters,
  REPORT_COMMENT_OPTIONS,
  REPORT_PROBLEM_OPTIONS,
  REPORT_SORT_OPTIONS,
  REPORT_STATUS_OPTIONS,
  REPORT_TARGET_OPTIONS,
  reportChips,
  reportFiltersActive,
  reportFiltersFromParams,
  reportFiltersToParams,
} from "./moderation-filters";
import { FilterSelect, FilterToggle, ModerationToolbar } from "./moderation-toolbar";

const QUERY_PREFIX = ["admin-moderation"] as const;
const STALE_TIME = 15_000;
/** The API returns at most this many reports per query (its default limit). */
const PAGE_LIMIT = 50;

/**
 * The reports tab. Story 39.12: its view (status, filters, sort, search) lives in the page address, owned by
 * the dashboard; this panel reads it and writes changes back.
 */
export function ReportsModerationPanel({ params, onParams }: { params: URLSearchParams; onParams: (next: URLSearchParams) => void }) {
  const queryClient = useQueryClient();
  const filters = reportFiltersFromParams(params);
  const [busyId, setBusyId] = useState<string | null>(null);
  // Story 39.11: hiding a comment is confirmed first.
  const [hiding, setHiding] = useState<ModerationReport | null>(null);

  const { data, isLoading, isError, isFetching } = useQuery({
    queryKey: [...QUERY_PREFIX, "reports", filters],
    queryFn: () => fetchModerationQueue(filters),
    staleTime: STALE_TIME,
  });

  const update = (next: ReportFilters) => onParams(reportFiltersToParams(next));
  const onSearch = useCallback(
    (search: string) => onParams(reportFiltersToParams({ ...reportFiltersFromParams(params), search })),
    [onParams, params],
  );

  async function run(id: string, action: () => Promise<boolean>): Promise<void> {
    setBusyId(id);
    await action();
    await queryClient.invalidateQueries({ queryKey: QUERY_PREFIX });
    setBusyId(null);
  }

  const chips = reportChips(filters);
  const shown = data?.reports.length ?? 0;

  return (
    <div className="grid gap-4">
      {/* Accounts over the threshold do not depend on the filters: they come first. */}
      {data && data.flagged.length > 0 ? (
        <FlaggedAccounts
          accounts={data.flagged}
          onActed={() => void queryClient.invalidateQueries({ queryKey: QUERY_PREFIX })}
          threshold={data.threshold}
        />
      ) : null}

      <ModerationToolbar
        active={reportFiltersActive(filters)}
        filters={
          <>
            <FilterSelect defaultValue="pending" label="Statut" onChange={(status) => update({ ...filters, status })} options={REPORT_STATUS_OPTIONS} value={filters.status} />
            <FilterSelect
              defaultValue="any"
              label="Cible"
              onChange={(targetType) => update({ ...filters, targetType })}
              options={REPORT_TARGET_OPTIONS}
              value={filters.targetType}
            />
            <FilterSelect defaultValue="any" label="Contenu" onChange={(problem) => update({ ...filters, problem })} options={REPORT_PROBLEM_OPTIONS} value={filters.problem} />
            <FilterSelect
              defaultValue="any"
              label="Commentaire"
              onChange={(commentState) => update({ ...filters, commentState })}
              options={REPORT_COMMENT_OPTIONS}
              value={filters.commentState}
            />
            <FilterToggle checked={filters.uncategorized} label="Non catégorisés" onChange={(uncategorized) => update({ ...filters, uncategorized })} />
          </>
        }
        onReset={() => update(clearedReportFilters(filters))}
        onSearch={onSearch}
        onSort={(sort) => update({ ...filters, sort })}
        resultLabel={
          data === undefined || data === null
            ? null
            : `${shown} signalement${shown > 1 ? "s" : ""}${shown >= PAGE_LIMIT ? ` (les ${PAGE_LIMIT} premiers)` : ""}`
        }
        search={filters.search}
        searchPlaceholder="Commentaire, raison ou auteur…"
        sort={filters.sort}
        sortOptions={REPORT_SORT_OPTIONS}
      />

      {isLoading ? (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement…
        </p>
      ) : isError || data === null || data === undefined ? (
        <p className="text-sm text-muted-foreground">Impossible de charger la file de modération.</p>
      ) : data.reports.length === 0 ? (
        <EmptyState filtered={chips.length > 0} onClear={() => update(clearedReportFilters(filters))} />
      ) : (
        <ul aria-busy={isFetching} className="divide-y divide-border rounded-lg border border-border bg-surface" role="list">
          {data.reports.map((report) => (
            <li key={report.id}>
              <ReportRow
                busy={busyId === report.id}
                onHide={() => setHiding(report)}
                onResolve={() => void run(report.id, () => resolveModerationReport(report.id))}
                onRestore={() => void run(report.id, () => restoreModerationComment(report.comment?.id ?? ""))}
                report={report}
              />
            </li>
          ))}
        </ul>
      )}

      <ConfirmDialog
        confirmLabel="Masquer"
        description="Le commentaire disparaît du profil pour tout le monde. Tu pourras le restaurer depuis cette file."
        onConfirm={() => {
          if (hiding === null) return;
          const report = hiding;
          void run(report.id, () => hideModerationComment(report.comment?.id ?? "")).then(() => setHiding(null));
        }}
        onOpenChange={(open) => (open ? undefined : setHiding(null))}
        open={hiding !== null}
        pending={hiding !== null && busyId === hiding.id}
        title="Masquer ce commentaire ?"
        tone="danger"
      />
    </div>
  );
}

function EmptyState({ filtered, onClear }: { filtered: boolean; onClear: () => void }) {
  return (
    <div className="grid justify-items-center gap-3 rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
      <p>{filtered ? "Aucun signalement ne correspond à ces filtres." : "Aucun signalement ici. 🎉"}</p>
      {filtered ? (
        <button className={buttonVariants({ variant: "secondary" })} onClick={onClear} type="button">
          Effacer les filtres
        </button>
      ) : null}
    </div>
  );
}

function ReportRow({
  report,
  busy,
  onHide,
  onRestore,
  onResolve,
}: {
  report: ModerationReport;
  busy: boolean;
  onHide: () => void;
  onRestore: () => void;
  onResolve: () => void;
}) {
  const comment = report.comment;

  return (
    <article className="grid gap-3 p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="inline-flex flex-wrap items-center gap-2 text-sm">
          <span className="rounded-full bg-red-500/15 px-2 py-0.5 text-xs font-semibold text-red-400">
            {report.targetType === "comment" ? "Commentaire" : report.targetType === "run_listing" ? "Annonce" : "Profil"}
          </span>
          <SeverityChip severity={report.severity} uncategorized={report.uncategorized} />
          <span className="text-muted-foreground">
            {CATEGORY_LABELS[report.category] ?? report.category} · {PROBLEM_LABELS[report.problem] ?? report.problem}
          </span>
        </span>
        <time className="text-xs text-muted-foreground" dateTime={report.createdAt}>
          {formatDate(report.createdAt)}
        </time>
      </div>

      {report.note ? <p className="border-l-2 border-border pl-3 text-sm italic text-foreground">« {report.note} »</p> : null}

      <p className="text-xs text-muted-foreground">
        Signalé par{" "}
        {report.reporter ? (
          <Link className="font-medium text-foreground hover:text-accent-text" href={`/joueurs/${report.reporter.slug}`}>
            {report.reporter.displayName ?? report.reporter.slug}
          </Link>
        ) : (
          "un membre"
        )}
      </p>

      {comment ? (
        <blockquote
          className={`border-l-2 border-red-500/50 pl-3 text-sm ${
            comment.hidden ? "text-muted-foreground line-through" : "text-foreground"
          }`}
        >
          {comment.body}
          <footer className="mt-1 text-xs not-italic text-muted-foreground">
            de{" "}
            {comment.author ? (
              <Link className="hover:text-accent-text" href={`/joueurs/${comment.author.slug}`}>
                {comment.author.displayName ?? comment.author.slug}
              </Link>
            ) : (
              "un membre"
            )}
            {comment.profileSlug ? (
              <>
                {" "}
                · sur le profil{" "}
                <Link className="hover:text-accent-text" href={`/joueurs/${comment.profileSlug}`}>
                  {comment.profileSlug}
                </Link>
              </>
            ) : null}
            {comment.hidden ? <span className="ml-1 font-semibold text-amber-400">(masqué)</span> : null}
          </footer>
        </blockquote>
      ) : report.runListing ? (
        <blockquote className="grid gap-1 rounded-lg border border-border bg-background/40 px-3 py-2 text-sm text-foreground">
          <p className="font-medium">Annonce « {report.runListing.title} »</p>
          <p className="whitespace-pre-line">{report.runListing.pitch ?? "(annonce retirée depuis)"}</p>
          {report.runListing.owner ? (
            <footer className="text-xs text-muted-foreground">
              par{" "}
              <Link className="hover:text-accent-text" href={`/joueurs/${report.runListing.owner.slug}`}>
                {report.runListing.owner.displayName ?? report.runListing.owner.slug}
              </Link>
            </footer>
          ) : null}
        </blockquote>
      ) : report.profile ? (
        <p className="text-sm text-foreground">
          Profil signalé :{" "}
          <Link className="font-medium hover:text-accent-text" href={`/joueurs/${report.profile.slug}`}>
            {report.profile.displayName ?? report.profile.slug}
          </Link>
        </p>
      ) : null}

      <div className="flex flex-wrap justify-end gap-2">
        {comment ? (
          comment.hidden ? (
            <ActionButton busy={busy} onClick={onRestore}>
              <Eye aria-hidden className="size-4" /> Restaurer
            </ActionButton>
          ) : (
            <ActionButton busy={busy} onClick={onHide}>
              <EyeOff aria-hidden className="size-4" /> Masquer
            </ActionButton>
          )
        ) : null}
        <ActionButton busy={busy} onClick={onResolve} primary>
          <ShieldCheck aria-hidden className="size-4" /> Résoudre
        </ActionButton>
      </div>
    </article>
  );
}

function ActionButton({
  children,
  busy,
  primary = false,
  onClick,
}: {
  children: React.ReactNode;
  busy: boolean;
  primary?: boolean;
  onClick: () => void;
}) {
  return (
    <button className={buttonVariants({ variant: primary ? "primary" : "secondary" })} disabled={busy} onClick={onClick} type="button">
      {children}
    </button>
  );
}

function SeverityChip({ severity, uncategorized }: { severity: number; uncategorized: boolean }) {
  if (uncategorized) {
    return <span className="rounded-full bg-muted/40 px-2 py-0.5 text-xs font-medium text-muted-foreground">Non catégorisé</span>;
  }
  const tone = severity >= 8 ? "bg-red-500/20 text-red-400" : severity >= 5 ? "bg-amber-500/20 text-amber-400" : "bg-sky-500/15 text-sky-400";
  return <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${tone}`}>Gravité {severity}</span>;
}

function formatDate(iso: string): string {
  const ts = new Date(iso);
  if (Number.isNaN(ts.getTime())) return "";
  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium", timeStyle: "short" }).format(ts);
}
