"use client";

import Link from "next/link";
import { ScrollText } from "lucide-react";
import { useQuery } from "@tanstack/react-query";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { PelleAmount } from "./pelle-amount";
import { fetchMyQuests, QuestHistoryPanel, questMaxReward, WeeklyQuestsPanel, type WeeklyQuests } from "./weekly-quests";
import { WelcomeQuestsPanel } from "./welcome-quests";

/**
 * Story 41.73: « Quêtes », the quests of the week on their own page - the quests at once, their progress when it is
 * counted, then the first steps of a newcomer and the weeks before.
 */
export function QuestsPanel() {
  return (
    <div className="grid gap-6">
      <WeeklyQuestsPanel />
      <WelcomeQuestsPanel />
      <QuestHistoryPanel />
    </div>
  );
}

const renewDay = new Intl.DateTimeFormat("fr-FR", { weekday: "long", day: "numeric", month: "long", timeZone: "Europe/Paris" });

/** Story 41.73: the most the week pays, every quest, tier and the chest included. */
export function weekMaxReward(quests: WeeklyQuests): number {
  return quests.quests.reduce((sum, quest) => sum + questMaxReward(quest), 0) + (quests.chest?.reward ?? 0);
}

/** Story 41.73: on « Pelles », the quests in a line - read without their progress - and the way to their page. */
export function QuestsSummary() {
  const { data } = useQuery({ queryKey: ["my-quests"], queryFn: fetchMyQuests, staleTime: DEFAULT_STALE_TIME, retry: false });

  return <QuestsSummaryView quests={data ?? null} />;
}

export function QuestsSummaryView({ quests }: { quests: WeeklyQuests | null }) {
  const count = quests?.quests.length ?? 0;

  return (
    <section aria-labelledby="quests-summary" className="flex flex-wrap items-center gap-4 rounded-xl border border-border p-5">
      <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-amber-400/15 text-amber-400">
        <ScrollText aria-hidden className="size-5" />
      </span>
      <div className="grid min-w-0 flex-[1_1_240px] gap-0.5">
        <h2 className="font-heading text-lg font-semibold text-foreground" id="quests-summary">
          Quêtes de la semaine
        </h2>
        {quests === null ? (
          <p className="text-sm text-muted-foreground">Gagne des pelles en jouant : de nouvelles quêtes chaque lundi.</p>
        ) : count === 0 && !quests.collective ? (
          <p className="text-sm text-muted-foreground">Pas de quête cette semaine.</p>
        ) : (
          <p className="text-sm text-muted-foreground">
            {count} quête{count > 1 ? "s" : ""}
            {quests.collective ? " et une quête collective" : ""}, jusqu&apos;à <PelleAmount amount={weekMaxReward(quests)} className="font-semibold" kind="gold" />
            {quests.chest ? " avec le coffre" : ""} · nouvelles quêtes le {renewDay.format(new Date(quests.renewsAt))}
          </p>
        )}
      </div>
      <Link className="inline-flex min-h-11 items-center rounded-lg border border-amber-400/60 px-4 text-sm font-semibold text-amber-400 hover:bg-amber-400/10" href="/compte/quetes">
        Voir mes quêtes
      </Link>
    </section>
  );
}
