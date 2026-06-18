"use client";

import { useEffect, useState } from "react";
import { Lock, Trophy } from "lucide-react";

import type { Achievement } from "@/features/players/player-profile-api";
import { fetchKudosState, kudosKey, type KudosState } from "./community-kudos-api";
import { KudosButton } from "./kudos-button";

const TARGET_ACHIEVEMENT = "achievement";

function formatDate(iso: string): string {
  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "long" }).format(new Date(iso));
}

/**
 * The "Succès" section. Renders server-fetched achievements and, for unlocked ones, a kudos button.
 * The viewer's own given-state is batch-fetched client-side (it depends on the authenticated cookie).
 */
export function ProfileAchievements({ achievements }: { achievements: Achievement[] }) {
  const [given, setGiven] = useState<Record<string, KudosState>>({});

  useEffect(() => {
    let cancelled = false;
    const targets = achievements
      .filter((a) => a.unlocked && a.grantId !== null)
      .map((a) => ({ targetType: TARGET_ACHIEVEMENT, targetId: a.grantId as string }));
    if (targets.length === 0) return;
    void (async () => {
      const state = await fetchKudosState(targets);
      if (!cancelled) setGiven(state);
    })();
    return () => {
      cancelled = true;
    };
  }, [achievements]);

  const unlockedCount = achievements.filter((a) => a.unlocked).length;
  const sorted = [...achievements].sort((a, b) => Number(b.unlocked) - Number(a.unlocked));

  return (
    <section className="grid gap-3">
      <h2 className="font-heading text-lg font-semibold text-foreground">
        Succès{" "}
        <span className="text-sm font-normal text-muted-foreground">
          ({unlockedCount}/{achievements.length})
        </span>
      </h2>
      <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" role="list">
        {sorted.map((achievement) => {
          const kudos =
            achievement.grantId !== null
              ? given[kudosKey({ targetType: TARGET_ACHIEVEMENT, targetId: achievement.grantId })]
              : undefined;
          return (
            <li
              className={`flex items-start gap-3 rounded-lg border p-4 ${
                achievement.unlocked ? "border-accent/40 bg-accent/5" : "border-border bg-surface/60 opacity-70"
              }`}
              key={achievement.key}
            >
              <span
                aria-hidden
                className={`flex size-9 shrink-0 items-center justify-center rounded-full ${
                  achievement.unlocked ? "bg-accent/15 text-accent-text" : "bg-surface text-muted-foreground"
                }`}
              >
                {achievement.unlocked ? <Trophy className="size-4" /> : <Lock className="size-4" />}
              </span>
              <div className="min-w-0">
                <p className={`text-sm font-semibold ${achievement.unlocked ? "text-foreground" : "text-muted-foreground"}`}>
                  {achievement.name}
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">{achievement.description}</p>
                {achievement.unlocked && achievement.unlockedAt ? (
                  <p className="mt-1 text-[11px] text-accent-text">
                    Débloqué le <time dateTime={achievement.unlockedAt}>{formatDate(achievement.unlockedAt)}</time>
                  </p>
                ) : null}
                {achievement.unlocked && achievement.grantId !== null ? (
                  <div className="mt-2">
                    <KudosButton
                      initialCount={kudos?.count ?? achievement.kudosCount}
                      initialGiven={kudos?.given ?? false}
                      key={`${achievement.grantId}-${kudos?.given ?? false}-${kudos?.count ?? achievement.kudosCount}`}
                      targetId={achievement.grantId}
                      targetType={TARGET_ACHIEVEMENT}
                    />
                  </div>
                ) : null}
              </div>
            </li>
          );
        })}
      </ul>
    </section>
  );
}
