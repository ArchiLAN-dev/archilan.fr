"use client";

import { useQuery } from "@tanstack/react-query";
import { CheckCircle2, Circle } from "lucide-react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";
import { PelleAmount } from "./pelle-amount";

/** Story 41.6: one quest of the week. */
export type WeeklyQuest = { key: string; label: string; reward: number; done: boolean; paid: boolean };

export type WeeklyQuests = { week: string; renewsAt: string; quests: WeeklyQuest[] };

function isQuest(v: unknown): v is WeeklyQuest {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "key") &&
    hasStringProp(v, "label") &&
    hasNumberProp(v, "reward") &&
    hasBooleanProp(v, "done") &&
    hasBooleanProp(v, "paid")
  );
}

export function isWeeklyQuests(v: unknown): v is WeeklyQuests {
  return typeof v === "object" && v !== null && hasStringProp(v, "week") && hasStringProp(v, "renewsAt") && "quests" in v && Array.isArray(v.quests) && v.quests.every(isQuest);
}

export async function fetchMyQuests(): Promise<WeeklyQuests | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/me/quests`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isWeeklyQuests(payload) ? payload : null;
  } catch {
    return null;
  }
}

const renewFormatter = new Intl.DateTimeFormat("fr-FR", { weekday: "long", day: "numeric", month: "long", timeZone: "Europe/Paris" });

export function WeeklyQuestsPanel() {
  const { data } = useQuery({ queryKey: ["my-quests"], queryFn: fetchMyQuests, staleTime: DEFAULT_STALE_TIME, retry: false });

  return data ? <WeeklyQuestsView quests={data} /> : null;
}

/** The quests of the week on « Mon portefeuille » (story 41.6): what each pays, done or not, paid or about to be. */
export function WeeklyQuestsView({ quests }: { quests: WeeklyQuests }) {
  return (
    <section aria-labelledby="weekly-quests" className="grid gap-3 rounded-xl border border-border p-5">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="font-heading text-xl font-semibold text-foreground" id="weekly-quests">
          Quêtes de la semaine
        </h2>
        <p className="text-xs text-muted-foreground">Nouvelles quêtes le {renewFormatter.format(new Date(quests.renewsAt))}</p>
      </div>
      <ul className="grid gap-2">
        {quests.quests.map((quest) => (
          <li className="flex items-center justify-between gap-3 text-sm" key={quest.key}>
            <span className="flex items-center gap-2">
              {quest.done ? (
                <CheckCircle2 aria-hidden className="size-4 text-success" />
              ) : (
                <Circle aria-hidden className="size-4 text-muted-foreground" />
              )}
              <span className={quest.done ? "text-foreground" : "text-muted-foreground"}>{quest.label}</span>
              <span className="sr-only">{quest.done ? "faite" : "à faire"}</span>
              {quest.done && !quest.paid ? <span className="text-xs text-muted-foreground">(créditée dans l&apos;heure)</span> : null}
            </span>
            <PelleAmount amount={quest.reward} className="shrink-0 text-xs font-semibold text-warning" signed />
          </li>
        ))}
      </ul>
    </section>
  );
}
