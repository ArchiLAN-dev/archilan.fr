"use client";

import { useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, ExternalLink, Loader2 } from "lucide-react";

import { Markdown } from "@/components/markdown/markdown";
import { buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import type { GameStep } from "@/features/games/public-games-api";
import {
  approveContribution,
  compareSteps,
  fetchContribution,
  rejectContribution,
  summarizeChanges,
  type ContributionItem,
  type StepChange,
  type StepComparison,
} from "./admin-game-contributions-api";
import { contributionStatus, QUERY_PREFIX } from "./contributions-moderation-panel";

const dateTime = new Intl.DateTimeFormat("fr-FR", { dateStyle: "long", timeStyle: "short", timeZone: "Europe/Paris" });

const CHANGE: Record<StepChange, { label: string; tone: string }> = {
  same: { label: "Identique", tone: "border-border text-muted-foreground" },
  modified: { label: "Modifiée", tone: "border-accent-warm/40 bg-accent-warm/10 text-accent-warm" },
  added: { label: "Ajoutée", tone: "border-success/40 bg-success/10 text-success" },
  removed: { label: "Retirée", tone: "border-danger/40 bg-danger/10 text-danger" },
};

/** `/admin/moderation/contributions/{id}` (story 39.16): one tutorial contribution, compared and decided. */
export function ContributionDetailPage({ id }: { id: string }) {
  const queryClient = useQueryClient();
  const listQuery = useSearchParams().get("liste") ?? "";
  const back = listQuery === "" ? "/admin/moderation/contributions" : `/admin/moderation/contributions?${listQuery}`;
  const { data, isLoading } = useQuery({
    queryKey: [...QUERY_PREFIX, "detail", id],
    queryFn: () => fetchContribution(id),
    staleTime: DEFAULT_STALE_TIME,
  });

  return (
    <section className="grid w-full min-w-0 grid-cols-1 gap-6 px-4 py-10">
      <Link className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground" href={back}>
        <ArrowLeft aria-hidden className="size-4" />
        Contributions
      </Link>
      {isLoading ? (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement…
        </p>
      ) : data?.kind === "ready" ? (
        <ContributionDetail
          item={data.item}
          onDecided={async () => {
            await queryClient.invalidateQueries({ queryKey: QUERY_PREFIX });
          }}
        />
      ) : (
        <p className="text-sm text-muted-foreground">{data?.kind === "not_found" ? "Cette contribution n'existe pas." : "Impossible de charger la contribution."}</p>
      )}
    </section>
  );
}

export function ContributionDetail({ item, onDecided }: { item: ContributionItem; onDecided: () => Promise<void> }) {
  const status = contributionStatus(item.status);
  const listed = item.gameSlug !== null;
  // The game's tutorial is read live: once decided, it may have been edited since, so only a pending proposal is compared.
  const compared = listed && item.status === "pending";
  const rows = compareSteps(compared ? item.currentSteps : [], item.proposedSteps);
  const counts = summarizeChanges(rows);
  const [busy, setBusy] = useState(false);

  async function decide(action: () => Promise<boolean>): Promise<void> {
    setBusy(true);
    await action();
    await onDecided();
    setBusy(false);
  }

  return (
    <div className="grid gap-6">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="grid min-w-0 gap-1">
          <h1 className="flex flex-wrap items-center gap-3 font-heading text-2xl font-bold text-foreground">
            {item.target || "Sans nom"}
            <span className={`rounded-full border px-2 py-0.5 text-xs font-medium ${status.tone}`}>{status.label}</span>
            {!listed ? <span className="rounded border border-warning/50 bg-warning/10 px-1.5 text-xs font-semibold text-warning">Jeu non listé</span> : null}
          </h1>
          <p className="text-sm text-muted-foreground">
            Proposé par {item.authorName || "un auteur inconnu"} le {dateTime.format(new Date(item.createdAt))}
          </p>
          {item.gameId ? (
            <Link className="inline-flex items-center gap-1 text-sm text-accent-text hover:underline" href={`/admin/jeux/${item.gameId}`}>
              <ExternalLink aria-hidden className="size-3.5" />
              Ouvrir le jeu dans l&apos;éditeur
            </Link>
          ) : null}
        </div>
        {item.status === "pending" ? <DecisionActions busy={busy} item={item} onApprove={(pelles) => decide(() => approveContribution(item.id, pelles))} onReject={(reason) => decide(() => rejectContribution(item.id, reason))} /> : null}
      </header>

      {item.status !== "pending" ? (
        <p className="rounded-lg border border-border bg-surface px-4 py-3 text-sm text-muted-foreground">
          {item.status === "approved" ? "Approuvée" : "Rejetée"}
          {item.reviewedAt ? ` le ${dateTime.format(new Date(item.reviewedAt))}` : ""}
          {item.rejectionReason ? ` : « ${item.rejectionReason} »` : "."}
        </p>
      ) : null}

      {item.message ? (
        <section className="grid gap-1">
          <h2 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Message de l&apos;auteur</h2>
          <Markdown className="border-l-2 border-border pl-3 text-sm text-muted-foreground" untrusted>
            {item.message}
          </Markdown>
        </section>
      ) : null}

      <section className="grid gap-3">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <h2 className="font-heading text-lg font-semibold text-foreground">{compared ? "Tutoriel actuel et proposé" : "Tutoriel proposé"}</h2>
          <p className="text-sm text-muted-foreground">{changeSummary(counts, compared)}</p>
        </div>
        <ol className="grid gap-2" role="list">
          {rows.map((row) => (
            <ComparisonRow compared={compared} key={row.position} row={row} />
          ))}
        </ol>
      </section>
    </div>
  );
}

/** « 2 modifiées, 1 ajoutée » - the changes only, or the size of the proposal when nothing is compared (unlisted game, decided contribution). */
export function changeSummary(counts: Record<StepChange, number>, compared: boolean): string {
  if (!compared) return `${counts.added} étape${counts.added > 1 ? "s" : ""}`;
  const parts = (
    [
      ["modified", "modifiée"],
      ["added", "ajoutée"],
      ["removed", "retirée"],
    ] as const
  )
    .filter(([key]) => counts[key] > 0)
    .map(([key, word]) => `${counts[key]} ${word}${counts[key] > 1 ? "s" : ""}`);
  return parts.length === 0 ? "Aucune différence avec le tutoriel actuel" : parts.join(", ");
}

/** One position of the comparison: an unchanged step folds to its title; a changed one shows both sides. */
function ComparisonRow({ row, compared }: { row: StepComparison; compared: boolean }) {
  const change = CHANGE[row.change];
  const title = (row.proposed ?? row.current)?.title ?? "";

  if (row.change === "same") {
    return (
      <li className="flex items-center gap-3 rounded-lg border border-border px-4 py-2 text-sm text-muted-foreground">
        <span className="tabular-nums">{row.position}.</span>
        <span className="min-w-0 flex-1 truncate">{title}</span>
        <span className={`rounded-full border px-2 py-0.5 text-xs ${change.tone}`}>{change.label}</span>
      </li>
    );
  }

  return (
    <li className="grid gap-3 rounded-lg border border-border bg-surface p-4">
      <div className="flex items-center gap-3">
        <span className="tabular-nums text-sm text-muted-foreground">{row.position}.</span>
        <span className="min-w-0 flex-1 truncate font-semibold text-foreground">{title}</span>
        {compared ? <span className={`rounded-full border px-2 py-0.5 text-xs font-medium ${change.tone}`}>{change.label}</span> : null}
      </div>
      <div className={`grid gap-3 ${compared ? "lg:grid-cols-2" : ""}`}>
        {compared ? <StepSide label="Actuel" step={row.current} /> : null}
        <StepSide accent label="Proposé" step={row.proposed} />
      </div>
    </li>
  );
}

function StepSide({ label, step, accent = false }: { label: string; step: GameStep | null; accent?: boolean }) {
  return (
    <div className="grid content-start gap-1 rounded border border-border bg-background/60 p-3">
      <p className={`text-xs font-semibold uppercase tracking-wide ${accent ? "text-accent-text" : "text-muted-foreground"}`}>{label}</p>
      {step === null ? (
        <p className="text-sm italic text-muted-foreground">Aucune étape à cette position.</p>
      ) : (
        <>
          <p className="text-sm font-semibold text-foreground">{step.title}</p>
          {step.description !== "" ? (
            <Markdown className="text-sm text-muted-foreground" untrusted>
              {step.description}
            </Markdown>
          ) : null}
          {step.videoUrl ? <p className="truncate text-xs text-muted-foreground">Vidéo : {step.videoUrl}</p> : null}
        </>
      )}
    </div>
  );
}

function DecisionActions({
  item,
  busy,
  onApprove,
  onReject,
}: {
  item: ContributionItem;
  busy: boolean;
  onApprove: (pelles: number) => Promise<void>;
  onReject: (reason: string) => Promise<void>;
}) {
  // Story 39.11: approving replaces the whole tutorial, so it is confirmed; rejecting asks its reason in a window.
  const [confirming, setConfirming] = useState(false);
  const [rejecting, setRejecting] = useState(false);
  // Story 41.5: gold pelles for the author, chosen at each approval; empty means none.
  const [pelles, setPelles] = useState("");
  const parsedPelles = Number.parseInt(pelles, 10);
  const reward = Number.isInteger(parsedPelles) && parsedPelles > 0 ? Math.min(parsedPelles, 1000) : 0;

  return (
    <div className="flex flex-wrap gap-2">
      <button className={buttonVariants({ variant: "secondary" })} disabled={busy} onClick={() => setRejecting(true)} type="button">
        Rejeter
      </button>
      <button className={buttonVariants({ variant: "primary" })} disabled={busy} onClick={() => setConfirming(true)} type="button">
        Approuver
      </button>

      <ConfirmDialog
        confirmLabel="Approuver"
        description={
          <>
            La version proposée <strong className="text-foreground">remplace l&apos;intégralité</strong> du tutoriel de {item.target}.
            <label className="mt-3 grid gap-1 text-sm">
              <span className="font-medium text-foreground">Pelles en or pour l&apos;auteur (0 à 1 000, vide = aucune)</span>
              <input
                className="min-h-9 rounded-lg border border-border bg-background px-3 text-sm text-foreground"
                inputMode="numeric"
                max={1000}
                min={0}
                onChange={(e) => setPelles(e.target.value)}
                type="number"
                value={pelles}
              />
            </label>
          </>
        }
        onConfirm={() => void onApprove(reward).then(() => setConfirming(false))}
        onOpenChange={setConfirming}
        open={confirming}
        pending={busy}
        title="Approuver cette contribution ?"
      />

      {rejecting ? <RejectDialog busy={busy} onClose={() => setRejecting(false)} onReject={onReject} target={item.target} /> : null}
    </div>
  );
}

function RejectDialog({ target, busy, onReject, onClose }: { target: string; busy: boolean; onReject: (reason: string) => Promise<void>; onClose: () => void }) {
  const [reason, setReason] = useState("");

  return (
    <Dialog description="La raison est envoyée à l'auteur de la contribution." onOpenChange={(open) => (open ? undefined : onClose())} open title={`Rejeter la contribution sur ${target}`}>
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
        <button className={buttonVariants({ variant: "danger" })} disabled={busy || reason.trim() === ""} onClick={() => void onReject(reason).then(onClose)} type="button">
          {busy ? <Loader2 aria-hidden className="size-4 animate-spin" /> : null}
          Rejeter
        </button>
      </DialogFooter>
    </Dialog>
  );
}
