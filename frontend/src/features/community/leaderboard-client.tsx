"use client";

import Link from "next/link";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { useState, useSyncExternalStore } from "react";
import { useAuth } from "@/features/auth/auth-context";
import { SESSION_STALE_TIME } from "@/lib/query-client";
import type { PublicEvent } from "@/features/events/event-types";
import {
  fetchLeaderboard,
  formatLeaderboardUnit,
  formatLeaderboardValue,
  type LeaderboardAxis,
  type LeaderboardResponse,
} from "./community-api";
import { MemberAvatar } from "./member-avatar";
import { TitledName } from "@/features/community/titled-name";
import { ProfileTitleBadge } from "@/features/community/profile-title-badge";
import { isTitleBadge } from "@/features/community/profile-title-catalog";

const TABS: { axis: LeaderboardAxis; label: string }[] = [
  { axis: "goals", label: "Objectifs" },
  { axis: "checks", label: "Checks" },
  { axis: "speed", label: "Vitesse" },
];

const PAGE_SIZE = 20;

/** Story 43.8: « Mes amis », kept in the URL. */
const FRIENDS_PARAM = "amis";
const URL_CHANGE = "leaderboard-url-change";

/**
 * Whether the URL asks for the friends board. Read from the location rather than `useSearchParams`, which would take
 * the statically rendered /communaute out of prerendering; the server render never has it.
 */
function subscribeToUrl(onChange: () => void): () => void {
  window.addEventListener("popstate", onChange);
  window.addEventListener(URL_CHANGE, onChange);
  return () => {
    window.removeEventListener("popstate", onChange);
    window.removeEventListener(URL_CHANGE, onChange);
  };
}

function urlAsksFriends(): boolean {
  return new URLSearchParams(window.location.search).get(FRIENDS_PARAM) === "1";
}

function setUrlFriends(on: boolean): void {
  const url = new URL(window.location.href);
  if (on) url.searchParams.set(FRIENDS_PARAM, "1");
  else url.searchParams.delete(FRIENDS_PARAM);
  window.history.replaceState(window.history.state, "", url);
  window.dispatchEvent(new Event(URL_CHANGE));
}

type Props = {
  initialData: LeaderboardResponse | null;
  initialDataFetchedAt: number;
  events: Pick<PublicEvent, "id" | "title">[];
};

export function LeaderboardClient({ initialData, initialDataFetchedAt, events }: Props) {
  const [axis, setAxis] = useState<LeaderboardAxis>("goals");
  const [eventId, setEventId] = useState<string>("");
  const [limit, setLimit] = useState(PAGE_SIZE);

  const { user } = useAuth();
  const friendsAsked = useSyncExternalStore(subscribeToUrl, urlAsksFriends, () => false);
  const friendsOnly = user !== null && friendsAsked;

  const activeEventId = eventId !== "" ? eventId : undefined;
  const isInitial = axis === "goals" && limit === PAGE_SIZE && !activeEventId && !friendsOnly;

  const { data, isPending } = useQuery({
    queryKey: ["leaderboard", axis, limit, activeEventId ?? null, friendsOnly],
    queryFn: () => fetchLeaderboard(axis, limit, activeEventId, friendsOnly),
    placeholderData: keepPreviousData,
    initialData: isInitial && initialData !== null ? initialData : undefined,
    initialDataUpdatedAt: isInitial ? initialDataFetchedAt : undefined,
    staleTime: SESSION_STALE_TIME,
  });

  function handleFriendsToggle() {
    setUrlFriends(!friendsOnly);
    setLimit(PAGE_SIZE);
  }

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

        <div className="flex shrink-0 flex-wrap items-center gap-3">
          {user !== null ? (
            <button
              aria-pressed={friendsOnly}
              className={[
                "inline-flex min-h-10 items-center rounded-full border px-4 text-sm font-semibold transition-colors",
                friendsOnly
                  ? "border-accent bg-accent/15 text-foreground"
                  : "border-border bg-surface text-muted-foreground hover:text-foreground",
              ].join(" ")}
              onClick={handleFriendsToggle}
              type="button"
            >
              Mes amis
            </button>
          ) : null}
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
      </div>

      {isPending && entries.length === 0 ? (
        <div className="flex items-center justify-center py-16">
          <p className="text-muted-foreground">Chargement…</p>
        </div>
      ) : entries.length === 0 ? (
        <div className="flex items-center justify-center py-16">
          <p className="text-muted-foreground">
            {friendsOnly ? "Ni toi ni tes amis n'apparaissent encore sur cet axe." : "Aucun résultat pour cet axe."}
          </p>
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

                <MemberAvatar
                  avatarAnimatedUrl={entry.avatarAnimatedUrl}
                  avatarUrl={entry.avatarUrl}
                  frame={entry.avatarFrame}
                  framing={entry.avatarFraming}
                  name={entry.displayName || entry.slug}
                  size={36}
                />

                <Link
                  className="min-w-0 flex-1 truncate font-semibold text-foreground hover:text-accent transition-colors"
                  href={`/joueurs/${entry.slug}`}
                >
                  <TitledName style={entry.nameStyle} variant="card">
                    {entry.displayName || entry.slug}
                  </TitledName>
                </Link>
                {/* Story 41.27: the title worn. */}
                {isTitleBadge(entry.title) ? <ProfileTitleBadge className="hidden shrink-0 sm:inline-flex" title={entry.title} variant="card" /> : null}
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
