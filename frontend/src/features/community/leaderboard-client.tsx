"use client";

import Link from "next/link";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { useState } from "react";
import { SESSION_STALE_TIME } from "@/lib/query-client";
import type { PublicEvent } from "@/features/events/event-types";
import {
  fetchLeaderboard,
  formatLeaderboardUnit,
  formatLeaderboardValue,
  type LeaderboardAxis,
  type LeaderboardResponse,
} from "./community-api";

const TABS: { axis: LeaderboardAxis; label: string }[] = [
  { axis: "goals", label: "Objectifs" },
  { axis: "checks", label: "Checks" },
  { axis: "speed", label: "Vitesse" },
];

const PAGE_SIZE = 20;

type Props = {
  initialData: LeaderboardResponse | null;
  initialDataFetchedAt: number;
  events: Pick<PublicEvent, "id" | "title">[];
};

export function LeaderboardClient({ initialData, initialDataFetchedAt, events }: Props) {
  const [axis, setAxis] = useState<LeaderboardAxis>("goals");
  const [eventId, setEventId] = useState<string>("");
  const [limit, setLimit] = useState(PAGE_SIZE);

  const activeEventId = eventId !== "" ? eventId : undefined;

  const { data, isPending } = useQuery({
    queryKey: ["leaderboard", axis, limit, activeEventId ?? null],
    queryFn: () => fetchLeaderboard(axis, limit, activeEventId),
    placeholderData: keepPreviousData,
    initialData:
      axis === "goals" && limit === PAGE_SIZE && !activeEventId && initialData !== null
        ? initialData
        : undefined,
    initialDataUpdatedAt: axis === "goals" && limit === PAGE_SIZE && !activeEventId ? initialDataFetchedAt : undefined,
    staleTime: SESSION_STALE_TIME,
  });

  function handleAxisChange(next: LeaderboardAxis) {
    setAxis(next);
    setLimit(PAGE_SIZE);
  }

  function handleEventChange(e: React.ChangeEvent<HTMLSelectElement>) {
    setEventId(e.target.value);
    setLimit(PAGE_SIZE);
  }

  const entries = data?.data ?? [];
  const total = data?.meta.total ?? 0;
  const canLoadMore = total > limit;

  return (
    <div className="grid gap-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex overflow-x-auto border-b border-border" role="tablist" aria-label="Axes du classement">
          {TABS.map((tab) => (
            <button
              key={tab.axis}
              role="tab"
              aria-selected={axis === tab.axis}
              className={[
                "shrink-0 border-b-2 px-5 py-3 text-sm font-semibold transition-colors",
                axis === tab.axis
                  ? "border-accent text-foreground"
                  : "border-transparent text-muted-foreground hover:text-foreground",
              ].join(" ")}
              type="button"
              onClick={() => handleAxisChange(tab.axis)}
            >
              {tab.label}
            </button>
          ))}
        </div>

        {events.length > 0 ? (
          <div className="shrink-0">
            <label className="sr-only" htmlFor="event-filter">
              Filtrer par événement
            </label>
            <select
              className="min-h-10 rounded border border-border bg-surface px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-accent"
              id="event-filter"
              value={eventId}
              onChange={handleEventChange}
            >
              <option value="">Tous les événements</option>
              {events.map((event) => (
                <option key={event.id} value={event.id}>
                  {event.title}
                </option>
              ))}
            </select>
          </div>
        ) : null}
      </div>

      {isPending && entries.length === 0 ? (
        <div className="flex items-center justify-center py-16">
          <p className="text-muted-foreground">Chargement…</p>
        </div>
      ) : entries.length === 0 ? (
        <div className="flex items-center justify-center py-16">
          <p className="text-muted-foreground">Aucun résultat pour cet axe.</p>
        </div>
      ) : (
        <div className="grid gap-2">
          {entries.map((entry) => (
            <div
              key={entry.slug}
              className="flex flex-col gap-2 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:items-center sm:gap-4 sm:px-4 sm:py-3"
            >
              <div className="flex min-w-0 items-center gap-3">
                <span className="w-6 shrink-0 text-center text-sm font-semibold text-muted-foreground">
                  {entry.rank}
                </span>

                <PlayerAvatar avatarUrl={entry.avatarUrl} displayName={entry.displayName} slug={entry.slug} />

                <Link
                  className="min-w-0 flex-1 truncate font-semibold text-foreground hover:text-accent transition-colors"
                  href={`/joueurs/${entry.slug}`}
                >
                  {entry.displayName || entry.slug}
                </Link>
              </div>

              <div className="shrink-0 pl-9 sm:ml-auto sm:pl-0 sm:text-right">
                <span className="font-semibold text-foreground">
                  {formatLeaderboardValue(entry.value, axis)}
                </span>
                {axis !== "speed" ? (
                  <span className="ml-1 text-xs text-muted-foreground">
                    {formatLeaderboardUnit(entry.value, axis)}
                  </span>
                ) : null}
              </div>
            </div>
          ))}
        </div>
      )}

      {canLoadMore ? (
        <div className="flex justify-center">
          <button
            className="inline-flex min-h-10 items-center justify-center rounded border border-border bg-surface px-6 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:opacity-50"
            disabled={isPending}
            type="button"
            onClick={() => setLimit((prev) => prev + PAGE_SIZE)}
          >
            {isPending ? "Chargement…" : "Voir plus"}
          </button>
        </div>
      ) : null}
    </div>
  );
}

function PlayerAvatar({
  avatarUrl,
  displayName,
  slug,
}: {
  avatarUrl: string | null;
  displayName: string;
  slug: string;
}) {
  const [failed, setFailed] = useState(false);
  const initial = (displayName || slug).charAt(0).toUpperCase();

  // A snapshotted Discord/Steam URL can later 404 - fall back to the initial on load error, never a
  // broken image (mirrors ProfileAvatar).
  if (avatarUrl !== null && !failed) {
    return (
      // eslint-disable-next-line @next/next/no-img-element -- external Discord/Steam CDN URL, not a local asset
      <img
        alt=""
        aria-hidden="true"
        className="size-9 shrink-0 rounded-full bg-surface object-cover"
        onError={() => setFailed(true)}
        src={avatarUrl}
      />
    );
  }

  return (
    <div
      aria-hidden="true"
      className="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent/15 text-sm font-bold text-accent-text"
    >
      {initial}
    </div>
  );
}
