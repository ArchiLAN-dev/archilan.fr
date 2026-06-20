"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { EyeOff, Eye, Loader2, Search, ShieldCheck } from "lucide-react";

import { AccountModerationControls } from "./account-moderation-controls";

import {
  CATEGORY_LABELS,
  DEFAULT_REPORT_FILTERS,
  type FlaggedAccount,
  fetchModerationQueue,
  hideModerationComment,
  PROBLEM_LABELS,
  resolveModerationReport,
  restoreModerationComment,
  type ModerationReport,
  type ReportCommentState,
  type ReportFilters,
  type ReportProblem,
  type ReportSort,
  type ReportStatus,
  type ReportTargetType,
} from "./admin-moderation-api";

const QUERY_PREFIX = ["admin-moderation"] as const;
const STALE_TIME = 15_000;
const SEARCH_DEBOUNCE_MS = 300;

const STATUS_OPTIONS: { value: ReportStatus; label: string }[] = [
  { value: "pending", label: "En attente" },
  { value: "resolved", label: "Résolus" },
  { value: "all", label: "Tous" },
];

const COMMENT_OPTIONS: { value: ReportCommentState; label: string }[] = [
  { value: "any", label: "Tous états" },
  { value: "hidden", label: "Masqués" },
  { value: "visible", label: "Visibles" },
];

const TARGET_OPTIONS: { value: ReportTargetType; label: string }[] = [
  { value: "any", label: "Toutes cibles" },
  { value: "comment", label: "Commentaires" },
  { value: "profile", label: "Profils" },
];

const SORT_OPTIONS: { value: ReportSort; label: string }[] = [
  { value: "severity", label: "Gravité" },
  { value: "recent", label: "Plus récents" },
  { value: "oldest", label: "Plus anciens" },
];

const PROBLEM_OPTIONS: { value: ReportProblem; label: string }[] = [
  { value: "any", label: "Tous contenus" },
  { value: "nudity", label: "Nudité" },
  { value: "violence", label: "Violence" },
  { value: "hate", label: "Haine" },
  { value: "harassment", label: "Harcèlement" },
  { value: "spam", label: "Spam" },
  { value: "other", label: "Autre" },
];

export function ReportsModerationPanel() {
  const queryClient = useQueryClient();
  const [status, setStatus] = useState<ReportStatus>("pending");
  const [commentState, setCommentState] = useState<ReportCommentState>("any");
  const [targetType, setTargetType] = useState<ReportTargetType>("any");
  const [problem, setProblem] = useState<ReportProblem>("any");
  const [uncategorized, setUncategorized] = useState(false);
  const [sort, setSort] = useState<ReportSort>("severity");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [busyId, setBusyId] = useState<string | null>(null);

  useEffect(() => {
    const handle = setTimeout(() => setSearch(searchInput.trim()), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(handle);
  }, [searchInput]);

  const filters: ReportFilters = { status, commentState, targetType, problem, uncategorized, sort, search };
  const { data, isLoading, isError, isFetching } = useQuery({
    queryKey: [...QUERY_PREFIX, "reports", filters],
    queryFn: () => fetchModerationQueue(filters),
    staleTime: STALE_TIME,
  });

  async function run(id: string, action: () => Promise<boolean>): Promise<void> {
    setBusyId(id);
    await action();
    await queryClient.invalidateQueries({ queryKey: QUERY_PREFIX });
    setBusyId(null);
  }

  const isDefault =
    status === DEFAULT_REPORT_FILTERS.status &&
    commentState === DEFAULT_REPORT_FILTERS.commentState &&
    targetType === DEFAULT_REPORT_FILTERS.targetType &&
    problem === DEFAULT_REPORT_FILTERS.problem &&
    !uncategorized &&
    search === "";

  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap items-center gap-2" role="tablist" aria-label="Statut des signalements">
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
          Commentaire
          <FilterSelect
            onChange={(value) => setCommentState(value as ReportCommentState)}
            options={COMMENT_OPTIONS}
            value={commentState}
          />
        </label>
        <label className="grid gap-1 text-xs font-medium text-muted-foreground">
          Cible
          <FilterSelect
            onChange={(value) => setTargetType(value as ReportTargetType)}
            options={TARGET_OPTIONS}
            value={targetType}
          />
        </label>
        <label className="grid gap-1 text-xs font-medium text-muted-foreground">
          Contenu
          <FilterSelect onChange={(value) => setProblem(value as ReportProblem)} options={PROBLEM_OPTIONS} value={problem} />
        </label>
        <label className="grid gap-1 text-xs font-medium text-muted-foreground">
          Tri
          <FilterSelect onChange={(value) => setSort(value as ReportSort)} options={SORT_OPTIONS} value={sort} />
        </label>
        <label className="flex min-h-9 cursor-pointer items-center gap-2 self-end rounded-lg border border-border px-3 text-xs font-medium text-muted-foreground">
          <input checked={uncategorized} className="accent-accent" onChange={(e) => setUncategorized(e.target.checked)} type="checkbox" />
          Non catégorisés
        </label>
        <label className="grid flex-1 gap-1 text-xs font-medium text-muted-foreground">
          Recherche
          <span className="relative">
            <Search aria-hidden className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <input
              className="min-h-9 w-full rounded-lg border border-border bg-background pl-9 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none"
              onChange={(event) => setSearchInput(event.target.value)}
              placeholder="Commentaire, raison ou auteur…"
              type="search"
              value={searchInput}
            />
          </span>
        </label>
      </div>

      {data && data.flagged.length > 0 ? (
        <FlaggedAccounts
          accounts={data.flagged}
          onActed={() => void queryClient.invalidateQueries({ queryKey: QUERY_PREFIX })}
          threshold={data.threshold}
        />
      ) : null}

      {isLoading ? (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement…
        </p>
      ) : isError || data === null || data === undefined ? (
        <p className="text-sm text-muted-foreground">Impossible de charger la file de modération.</p>
      ) : data.reports.length === 0 ? (
        <p className="rounded-lg border border-border bg-surface px-4 py-8 text-center text-sm text-muted-foreground">
          {isDefault ? "Aucun signalement en attente. 🎉" : "Aucun signalement ne correspond à ces filtres."}
        </p>
      ) : (
        <ul aria-busy={isFetching} className="grid gap-4" role="list">
          {data.reports.map((report) => (
            <li key={report.id}>
              <ReportCard
                busy={busyId === report.id}
                onHide={() => void run(report.id, () => hideModerationComment(report.comment?.id ?? ""))}
                onResolve={() => void run(report.id, () => resolveModerationReport(report.id))}
                onRestore={() => void run(report.id, () => restoreModerationComment(report.comment?.id ?? ""))}
                report={report}
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

function ReportCard({
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
    <article className="grid gap-3 rounded-lg border border-border bg-surface p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="inline-flex flex-wrap items-center gap-2 text-sm">
          <span className="rounded-full bg-red-500/15 px-2 py-0.5 text-xs font-semibold text-red-400">
            {report.targetType === "comment" ? "Commentaire" : "Profil"}
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

      {report.note ? <p className="rounded-md border border-border bg-background/50 px-3 py-2 text-sm text-foreground">« {report.note} »</p> : null}

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
          className={`rounded-md border border-border bg-background/50 px-3 py-2 text-sm ${
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
      ) : report.profile ? (
        <p className="text-sm text-foreground">
          Profil signalé :{" "}
          <Link className="font-medium hover:text-accent-text" href={`/joueurs/${report.profile.slug}`}>
            {report.profile.displayName ?? report.profile.slug}
          </Link>
        </p>
      ) : null}

      <div className="flex flex-wrap gap-2">
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
    <button
      className={`inline-flex min-h-9 items-center gap-1.5 rounded-lg border px-3 text-sm font-semibold transition-colors disabled:opacity-50 ${
        primary
          ? "border-accent bg-accent text-white hover:bg-accent-hover"
          : "border-border text-muted-foreground hover:border-accent hover:text-foreground"
      }`}
      disabled={busy}
      onClick={onClick}
      type="button"
    >
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

function FlaggedAccounts({ accounts, threshold, onActed }: { accounts: FlaggedAccount[]; threshold: number; onActed: () => void }) {
  return (
    <section className="grid gap-3 rounded-lg border border-amber-500/40 bg-amber-500/5 p-4" aria-label="Comptes à examiner">
      <h3 className="flex items-center gap-2 text-sm font-semibold text-amber-400">
        <ShieldCheck aria-hidden className="size-4" /> À examiner — comptes au-delà du seuil ({threshold})
      </h3>
      <ul className="grid gap-3" role="list">
        {accounts.map((account) => (
          <li className="grid gap-2 rounded-md border border-border bg-surface/60 p-3" key={account.userId}>
            <div className="flex items-center justify-between gap-3 text-sm">
              {account.slug ? (
                <Link className="font-medium text-foreground hover:text-accent-text" href={`/joueurs/${account.slug}`}>
                  {account.displayName ?? account.slug}
                </Link>
              ) : (
                <span className="text-muted-foreground">Compte supprimé</span>
              )}
              <span className="text-xs text-muted-foreground">
                score <strong className="text-amber-400">{account.score}</strong> · {account.reportCount} signalement{account.reportCount > 1 ? "s" : ""}
              </span>
            </div>
            <AccountModerationControls name={account.displayName ?? account.slug ?? "ce compte"} onActed={onActed} userId={account.userId} />
          </li>
        ))}
      </ul>
    </section>
  );
}

function formatDate(iso: string): string {
  const ts = new Date(iso);
  if (Number.isNaN(ts.getTime())) return "";
  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium", timeStyle: "short" }).format(ts);
}
