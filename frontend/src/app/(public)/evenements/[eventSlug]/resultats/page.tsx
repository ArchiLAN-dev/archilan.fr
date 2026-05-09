import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { CheckCircle2, Clock, Trophy } from "lucide-react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { getPublicEvent } from "@/features/events/public-events-api";

type ResultsPageProps = {
  params: Promise<{ eventSlug: string }>;
};

type SlotResult = {
  slot_name: string;
  player: string;
  game: string;
  checks_done: number;
  items_received: number;
  goal_reached_at: string | null;
};

type SessionResult = {
  id: string;
  status: string;
  startedAt: string | null;
  finishedAt: string | null;
};

type ResultsData = {
  session: SessionResult;
  slots: SlotResult[];
};

export async function generateMetadata({ params }: ResultsPageProps): Promise<Metadata> {
  const { eventSlug } = await params;
  const event = await getPublicEvent(eventSlug);

  return {
    title: event ? `Résultats - ${event.title}` : "Résultats de session",
    description: event
      ? `Résultats et classement de la session Archipelago de l'événement ${event.title}.`
      : "Résultats de session Archipelago.",
    robots: { index: false, follow: true },
  };
}

async function fetchResults(eventId: string): Promise<ResultsData | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/events/${eventId}/session/results`, {
      cache: "no-store",
    });

    if (!res.ok) return null;

    const payload: unknown = await res.json();
    if (!isResultsPayload(payload) || payload.data === null) return null;

    return payload.data;
  } catch {
    return null;
  }
}

function isResultsPayload(
  payload: unknown,
): payload is { data: ResultsData | null } {
  return Boolean(payload && typeof payload === "object" && "data" in payload);
}

function formatDuration(startedAt: string | null, finishedAt: string | null): string | null {
  if (!startedAt || !finishedAt) return null;
  const diffMs = new Date(finishedAt).getTime() - new Date(startedAt).getTime();
  if (diffMs <= 0) return null;
  const h = Math.floor(diffMs / 3_600_000);
  const m = Math.floor((diffMs % 3_600_000) / 60_000);
  return h > 0 ? `${h}h${String(m).padStart(2, "0")}` : `${m}min`;
}

function formatTime(iso: string | null): string {
  if (!iso) return "";
  return new Intl.DateTimeFormat("fr-FR", { timeStyle: "short" }).format(new Date(iso));
}

export default async function ResultatsPage({ params }: ResultsPageProps) {
  const { eventSlug } = await params;
  const [event, results] = await Promise.all([
    getPublicEvent(eventSlug),
    fetchResults(eventSlug),
  ]);

  if (!event) {
    notFound();
  }

  const duration = results ? formatDuration(results.session.startedAt, results.session.finishedAt) : null;
  const goalCount = results ? results.slots.filter((s) => s.goal_reached_at !== null).length : 0;

  return (
    <div className="mx-auto w-full max-w-4xl">
      <header className="mb-10 grid gap-2">
        <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">
          {event.title}
        </p>
        <h1 className="font-heading text-4xl font-bold text-foreground">
          Résultats de la session
        </h1>
        {duration ? (
          <p className="flex items-center gap-2 text-sm text-muted-foreground">
            <Clock aria-hidden="true" className="size-4" />
            Durée totale : {duration}
          </p>
        ) : null}
      </header>

      {!results ? (
        <div className="rounded-lg border border-border p-8 text-center">
          <p className="text-muted-foreground">
            Aucun résultat disponible pour cet événement.
          </p>
        </div>
      ) : (
        <section aria-labelledby="results-heading">
          <div className="mb-4 flex items-center gap-3">
            <Trophy aria-hidden="true" className="size-5 text-accent-warm" />
            <h2 className="font-heading text-2xl font-semibold text-foreground" id="results-heading">
              Classement
            </h2>
            {goalCount > 0 ? (
              <span className="ml-auto text-sm text-muted-foreground">
                {goalCount} / {results.slots.length} objectifs atteints
              </span>
            ) : null}
          </div>

          <div className="overflow-x-auto rounded-lg border border-border">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border bg-surface text-left">
                  <th className="px-4 py-3 font-semibold text-muted-foreground" scope="col">
                    #
                  </th>
                  <th className="px-4 py-3 font-semibold text-muted-foreground" scope="col">
                    Joueur
                  </th>
                  <th className="px-4 py-3 font-semibold text-muted-foreground" scope="col">
                    Jeu
                  </th>
                  <th className="px-4 py-3 font-semibold text-muted-foreground" scope="col">
                    Checks
                  </th>
                  <th className="px-4 py-3 font-semibold text-muted-foreground" scope="col">
                    Items reçus
                  </th>
                  <th className="px-4 py-3 font-semibold text-muted-foreground" scope="col">
                    Goal
                  </th>
                </tr>
              </thead>
              <tbody>
                {results.slots.map((slot, index) => (
                  <tr
                    className="border-b border-border last:border-0 hover:bg-surface/50 transition-colors"
                    key={slot.slot_name}
                  >
                    <td className="px-4 py-3 tabular-nums text-muted-foreground">
                      {index + 1}
                    </td>
                    <td className="px-4 py-3 font-medium text-foreground">
                      {slot.player}
                      <span className="ml-1.5 text-xs text-muted-foreground">
                        ({slot.slot_name})
                      </span>
                    </td>
                    <td className="px-4 py-3 text-foreground">{slot.game}</td>
                    <td className="px-4 py-3 tabular-nums text-foreground">
                      {slot.checks_done}
                    </td>
                    <td className="px-4 py-3 tabular-nums text-foreground">
                      {slot.items_received}
                    </td>
                    <td className="px-4 py-3">
                      {slot.goal_reached_at ? (
                        <span className="inline-flex items-center gap-1.5 text-success">
                          <CheckCircle2 aria-hidden="true" className="size-4" />
                          {formatTime(slot.goal_reached_at)}
                        </span>
                      ) : (
                        <span className="text-muted-foreground">-</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </div>
  );
}
