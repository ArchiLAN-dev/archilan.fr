import Link from "next/link";
import type { RunResults, SlotResult } from "./run-results-api";

export function RunResultsPage({ runId, results }: { runId: string; results: RunResults }) {
  const completed = results.slots.filter((s) => s.completionSeconds !== null);
  const incomplete = results.slots.filter((s) => s.completionSeconds === null && !s.isInvalidated);
  const invalidated = results.slots.filter((s) => s.isInvalidated);

  return (
    <article className="mx-auto w-full max-w-5xl grid gap-12">
      <header className="grid gap-4 border-b border-border pb-8">
        <div>
          <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-text">
            Résultats de run
          </p>
          <h1 className="mt-2 font-heading text-3xl font-bold text-foreground md:text-4xl">
            {results.eventName}
          </h1>
        </div>

        <dl className="flex flex-wrap gap-6 text-sm text-muted-foreground">
          {results.startedAt ? (
            <div>
              <dt className="inline">Date&nbsp;: </dt>
              <dd className="inline font-semibold text-foreground">
                <time dateTime={results.startedAt}>{formatDate(results.startedAt)}</time>
              </dd>
            </div>
          ) : null}
          {results.durationSeconds !== null ? (
            <div>
              <dt className="inline">Durée&nbsp;: </dt>
              <dd className="inline font-semibold text-foreground">
                {formatDuration(results.durationSeconds)}
              </dd>
            </div>
          ) : null}
          <div>
            <dt className="inline">Slots&nbsp;: </dt>
            <dd className="inline font-semibold text-foreground">{results.slots.length}</dd>
          </div>
        </dl>

        <div className="flex flex-wrap gap-3">
          <Link
            className="inline-flex min-h-10 items-center justify-center gap-2 rounded border border-border bg-surface px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
            href={`/runs/${runId}`}
          >
            ← Retour à la run
          </Link>
          <Link
            className="inline-flex min-h-10 items-center justify-center gap-2 rounded border border-border bg-surface px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
            href="/classements"
          >
            Voir le classement communautaire
          </Link>
        </div>
      </header>

      {completed.length > 0 ? (
        <section aria-labelledby="section-completed" className="grid gap-4">
          <h2 className="font-heading text-xl font-semibold text-foreground" id="section-completed">
            Objectifs atteints{" "}
            <span className="text-base font-normal text-muted-foreground">({completed.length})</span>
          </h2>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {completed.map((slot) => (
              <SlotCard key={slot.slotId} slot={slot} />
            ))}
          </div>
        </section>
      ) : null}

      {incomplete.length > 0 ? (
        <section aria-labelledby="section-incomplete" className="grid gap-4">
          <h2 className="font-heading text-xl font-semibold text-foreground" id="section-incomplete">
            Incomplets{" "}
            <span className="text-base font-normal text-muted-foreground">({incomplete.length})</span>
          </h2>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {incomplete.map((slot) => (
              <SlotCard key={slot.slotId} slot={slot} />
            ))}
          </div>
        </section>
      ) : null}

      {invalidated.length > 0 ? (
        <section aria-labelledby="section-invalidated" className="grid gap-4">
          <h2 className="font-heading text-xl font-semibold text-foreground" id="section-invalidated">
            Forfaits{" "}
            <span className="text-base font-normal text-muted-foreground">({invalidated.length})</span>
          </h2>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {invalidated.map((slot) => (
              <SlotCard key={slot.slotId} slot={slot} />
            ))}
          </div>
        </section>
      ) : null}

      {results.slots.length === 0 ? (
        <p className="text-muted-foreground">Aucun slot enregistré pour cette run.</p>
      ) : null}
    </article>
  );
}

export function RunResultsNotFound({ runId }: { runId: string }) {
  return (
    <div className="mx-auto w-full max-w-2xl grid gap-6 py-16 text-center">
      <h1 className="font-heading text-3xl font-bold text-foreground">
        Résultats non disponibles
      </h1>
      <p className="text-muted-foreground">
        Cette run n&apos;existe pas ou n&apos;est pas encore terminée.
      </p>
      <Link
        className="mx-auto inline-flex min-h-11 items-center justify-center gap-2 rounded border border-border bg-surface px-5 font-semibold text-foreground transition-colors hover:border-accent"
        href={`/runs/${runId}`}
      >
        ← Retour à la run
      </Link>
    </div>
  );
}

function SlotCard({ slot }: { slot: SlotResult }) {
  const muted = slot.isInvalidated;

  return (
    <div
      className={`rounded-lg border p-4 grid gap-3 ${
        muted ? "border-border/60 bg-surface/60" : "border-border bg-surface"
      }`}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p
            className={`font-semibold truncate ${
              muted ? "text-muted-foreground" : "text-foreground"
            }`}
          >
            {slot.playerName || "—"}
          </p>
          <p
            className={`text-sm truncate ${
              muted ? "text-muted-foreground/70" : "text-muted-foreground"
            }`}
          >
            {slot.game || "—"}
          </p>
        </div>
        <StatusBadge slot={slot} />
      </div>

      <dl
        className={`grid grid-cols-2 gap-2 text-sm ${muted ? "text-muted-foreground/70" : ""}`}
      >
        <div>
          <dt className="text-xs text-muted-foreground">Checks</dt>
          <dd className="font-semibold">{slot.checksDone}</dd>
        </div>
        <div>
          <dt className="text-xs text-muted-foreground">Items reçus</dt>
          <dd className="font-semibold">{slot.itemsReceived}</dd>
        </div>
        {slot.completionSeconds !== null ? (
          <div className="col-span-2">
            <dt className="text-xs text-muted-foreground">Temps de complétion</dt>
            <dd className="font-semibold">{formatDuration(slot.completionSeconds)}</dd>
          </div>
        ) : null}
      </dl>

      {slot.isInvalidated ? (
        <p
          className="text-xs text-muted-foreground/70 italic"
          title="Statistiques exclues des classements (slot relâché)"
        >
          Statistiques exclues des classements (slot relâché)
        </p>
      ) : null}
    </div>
  );
}

function StatusBadge({ slot }: { slot: SlotResult }) {
  if (slot.isInvalidated) {
    return (
      <span className="shrink-0 rounded border border-amber-500/50 px-2 py-0.5 text-xs font-semibold text-amber-600 dark:text-amber-400">
        Forfait
      </span>
    );
  }

  if (slot.completionSeconds !== null) {
    return (
      <span className="shrink-0 rounded border border-success/50 px-2 py-0.5 text-xs font-semibold text-success">
        Objectif atteint
      </span>
    );
  }

  return (
    <span className="shrink-0 rounded border border-muted-foreground/40 px-2 py-0.5 text-xs font-semibold text-muted-foreground">
      Incomplet
    </span>
  );
}

function formatDuration(seconds: number): string {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  if (h === 0) return `${m}min`;
  if (m === 0) return `${h}h`;
  return `${h}h ${m}min`;
}

function formatDate(iso: string): string {
  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "long" }).format(new Date(iso));
}
