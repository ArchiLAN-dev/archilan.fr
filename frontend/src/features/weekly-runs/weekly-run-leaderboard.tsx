"use client";

import { useState } from "react";
import type { WeeklyRunLeaderboardEntry, WeeklyRunParticipant } from "./weekly-runs-api";

type Tab = "fastest" | "fewestChecks" | "fewestItems";

const TABS: { id: Tab; label: string }[] = [
  { id: "fastest", label: "Meilleur temps" },
  { id: "fewestChecks", label: "Moins de checks" },
  { id: "fewestItems", label: "Moins d'items" },
];

function formatTime(seconds: number): string {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
}

type LeaderboardRowProps = {
  rank: number;
  entry: WeeklyRunLeaderboardEntry;
  metric: string;
  isMe: boolean;
};

function LeaderboardRow({ rank, entry, metric, isMe }: LeaderboardRowProps) {
  return (
    <div
      className={[
        "flex items-center justify-between rounded px-3 py-2",
        isMe ? "bg-accent/10 ring-1 ring-accent/30" : "bg-surface-2/50",
      ].join(" ")}
    >
      <div className="flex items-center gap-3">
        <span className="w-6 text-center text-sm font-bold text-muted-foreground">{rank}</span>
        <span className={["text-sm", isMe ? "font-bold text-foreground" : "text-foreground"].join(" ")}>
          {entry.displayName ?? "Joueur inconnu"}
          {isMe && <span className="ml-1.5 text-xs text-accent-text">(vous)</span>}
        </span>
      </div>
      <span className="font-mono text-sm text-muted-foreground">{metric}</span>
    </div>
  );
}

type Props = {
  leaderboard: {
    fastest: WeeklyRunLeaderboardEntry[];
    fewestChecks: WeeklyRunLeaderboardEntry[];
    fewestItems: WeeklyRunLeaderboardEntry[];
  };
  participants: WeeklyRunParticipant[];
  myEntryId: string | null;
  myUserId: string | null;
};

export function WeeklyRunLeaderboard({ leaderboard, participants, myEntryId, myUserId }: Props) {
  const [activeTab, setActiveTab] = useState<Tab>("fastest");

  const rows =
    activeTab === "fastest"
      ? leaderboard.fastest
      : activeTab === "fewestChecks"
        ? leaderboard.fewestChecks
        : leaderboard.fewestItems;

  function getMetric(entry: WeeklyRunLeaderboardEntry): string {
    if (activeTab === "fastest") {
      return entry.completionTimeSeconds != null ? formatTime(entry.completionTimeSeconds) : "-";
    }
    if (activeTab === "fewestChecks") {
      return entry.checksTotal != null ? String(entry.checksTotal) : "-";
    }
    return entry.itemsTotal != null ? String(entry.itemsTotal) : "-";
  }

  return (
    <div className="flex flex-col gap-4">
      {/* Tab bar */}
      <div className="flex gap-1 rounded-lg bg-surface-2 p-1">
        {TABS.map((tab) => (
          <button
            className={[
              "flex-1 rounded px-2 py-1.5 text-xs font-medium transition-colors",
              activeTab === tab.id
                ? "bg-surface text-foreground shadow-sm"
                : "text-muted-foreground hover:text-foreground",
            ].join(" ")}
            key={tab.id}
            onClick={() => setActiveTab(tab.id)}
            type="button"
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* Leaderboard rows */}
      {rows.length === 0 ? (
        <p className="py-4 text-center text-sm text-muted-foreground">
          Aucun objectif complété pour l&apos;instant.
        </p>
      ) : (
        <div className="flex flex-col gap-1.5">
          {rows.map((entry, i) => (
            <LeaderboardRow
              entry={entry}
              isMe={entry.entryId === myEntryId || entry.userId === myUserId}
              key={entry.entryId}
              metric={getMetric(entry)}
              rank={i + 1}
            />
          ))}
        </div>
      )}

      {/* Participants list */}
      <div className="mt-2 border-t border-border pt-4">
        <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          Participants en cours
        </p>
        {participants.length === 0 ? (
          <p className="text-sm text-muted-foreground">Aucun participant.</p>
        ) : (
          <div className="flex flex-wrap gap-2">
            {participants.map((p) => (
              <span
                className={[
                  "inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium",
                  p.entryId === myEntryId || p.userId === myUserId
                    ? "bg-accent/15 text-accent-text ring-1 ring-accent/30"
                    : "bg-surface-2 text-foreground",
                ].join(" ")}
                key={`${p.userId}-${p.attemptNumber}`}
              >
                {p.displayName ?? "Joueur inconnu"}
                {p.goalReachedAt != null && (
                  <span aria-label="Objectif atteint" className="text-emerald-500">
                    ✓
                  </span>
                )}
              </span>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
