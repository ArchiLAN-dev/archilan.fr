"use client";

import Link from "next/link";
import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { EyeOff, Eye, Loader2, ShieldCheck } from "lucide-react";

import {
  fetchModerationQueue,
  hideModerationComment,
  resolveModerationReport,
  restoreModerationComment,
  type ModerationReport,
} from "./admin-moderation-api";

const QUERY_KEY = ["admin-moderation"] as const;
const STALE_TIME = 15_000;

export function AdminModerationDashboard() {
  const queryClient = useQueryClient();
  const { data, isLoading, isError } = useQuery({
    queryKey: QUERY_KEY,
    queryFn: fetchModerationQueue,
    staleTime: STALE_TIME,
  });
  const [busyId, setBusyId] = useState<string | null>(null);

  async function run(id: string, action: () => Promise<boolean>): Promise<void> {
    setBusyId(id);
    await action();
    await queryClient.invalidateQueries({ queryKey: QUERY_KEY });
    setBusyId(null);
  }

  return (
    <section className="grid gap-6">
      <header className="grid gap-1">
        <h1 className="font-heading text-2xl font-bold text-foreground">Modération</h1>
        <p className="text-sm text-muted-foreground">
          File des signalements en attente
          {data ? <span className="font-semibold text-foreground"> · {data.count}</span> : null}.
        </p>
      </header>

      {isLoading ? (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement…
        </p>
      ) : isError || data === null || data === undefined ? (
        <p className="text-sm text-muted-foreground">Impossible de charger la file de modération.</p>
      ) : data.reports.length === 0 ? (
        <p className="rounded-lg border border-border bg-surface px-4 py-8 text-center text-sm text-muted-foreground">
          Aucun signalement en attente. 🎉
        </p>
      ) : (
        <ul className="grid gap-4" role="list">
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
    </section>
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
        <span className="inline-flex items-center gap-2 text-sm">
          <span className="rounded-full bg-red-500/15 px-2 py-0.5 text-xs font-semibold text-red-400">
            {report.targetType === "comment" ? "Commentaire" : "Profil"}
          </span>
          <span className="text-muted-foreground">{report.reason}</span>
        </span>
        <time className="text-xs text-muted-foreground" dateTime={report.createdAt}>
          {formatDate(report.createdAt)}
        </time>
      </div>

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

function formatDate(iso: string): string {
  const ts = new Date(iso);
  if (Number.isNaN(ts.getTime())) return "";
  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium", timeStyle: "short" }).format(ts);
}
