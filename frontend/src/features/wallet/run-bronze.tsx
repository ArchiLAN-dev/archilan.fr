"use client";

import { useQuery } from "@tanstack/react-query";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";

import { PelleAmount } from "./pelle-amount";
import { pelleReasonLabel } from "./wallet-api";

// Story 41.38: a player's bronze pelles in one private run - earned by playing it, spent on its hints and bounties,
// burned when it ends.

export type RunBronzeLine = { reason: string; label: string; amount: number; count: number; at: string };

export type RunBronze = {
  balance: number;
  ended: boolean;
  start: number;
  perCheck: number;
  perGoal: number;
  hints: boolean;
  itemHintPrice: number;
  locationHintPrice: number;
  bounties: boolean;
  history: RunBronzeLine[];
};

function isLine(v: unknown): v is RunBronzeLine {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "reason") &&
    hasStringProp(v, "label") &&
    hasNumberProp(v, "amount") &&
    hasNumberProp(v, "count") &&
    hasStringProp(v, "at")
  );
}

export function isRunBronze(v: unknown): v is RunBronze {
  return (
    typeof v === "object" &&
    v !== null &&
    hasNumberProp(v, "balance") &&
    hasBooleanProp(v, "ended") &&
    hasNumberProp(v, "start") &&
    hasNumberProp(v, "perCheck") &&
    hasNumberProp(v, "perGoal") &&
    hasBooleanProp(v, "hints") &&
    hasNumberProp(v, "itemHintPrice") &&
    hasNumberProp(v, "locationHintPrice") &&
    hasBooleanProp(v, "bounties") &&
    "history" in v &&
    Array.isArray(v.history) &&
    v.history.every(isLine)
  );
}

export async function fetchRunBronze(runId: string): Promise<RunBronze | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}/bronze`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isRunBronze(payload) ? payload : null;
  } catch {
    return null;
  }
}

/** « +1 par check · +25 au goal », the sources that are on. */
export function earningsLabel(bronze: Pick<RunBronze, "start" | "perCheck" | "perGoal">): string | null {
  const parts = [
    bronze.start > 0 ? `${bronze.start} au départ` : null,
    bronze.perCheck > 0 ? `+${bronze.perCheck} par check` : null,
    bronze.perGoal > 0 ? `+${bronze.perGoal} au goal` : null,
  ].filter((part): part is string => part !== null);

  return parts.length > 0 ? parts.join(" · ") : null;
}

/** What one line of the history reads: grouped checks say how many. */
export function lineLabel(line: RunBronzeLine): string {
  if (line.reason === "bronze_check") return `${line.count} ${line.count > 1 ? "checks" : "check"}`;
  return line.label !== "" ? line.label : pelleReasonLabel(line.reason);
}

const dateFormatter = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" });

export function RunBronzeCard({ runId }: { runId: string }) {
  const { data } = useQuery({
    queryKey: ["run-bronze", runId],
    queryFn: () => fetchRunBronze(runId),
    staleTime: DEFAULT_STALE_TIME,
  });
  if (!data) return null;

  const earnings = earningsLabel(data);
  const spending = [
    data.hints ? `indice d'objet ${data.itemHintPrice}, de lieu ${data.locationHintPrice}` : null,
    data.bounties ? "primes sur les objets" : null,
  ].filter((part): part is string => part !== null);

  return (
    <section aria-labelledby="run-bronze-title" className="grid gap-3 rounded-lg border border-border bg-surface p-4">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-sm font-semibold text-foreground" id="run-bronze-title">
          Mes pelles de bronze
        </h2>
        <PelleAmount amount={data.balance} className="font-heading text-2xl font-semibold text-foreground" />
      </div>
      <p className="text-xs text-muted-foreground">
        {data.ended
          ? "La partie est finie : son bronze a disparu."
          : "Propres à cette partie : elles paient ses indices et ses primes, et disparaissent à sa fin. Ton or reste pour la boutique."}
      </p>
      {earnings !== null || spending.length > 0 ? (
        <dl className="grid gap-1 text-xs sm:grid-cols-2">
          {earnings !== null ? (
            <div>
              <dt className="text-muted-foreground">Gains</dt>
              <dd className="text-foreground">{earnings}</dd>
            </div>
          ) : null}
          {spending.length > 0 ? (
            <div>
              <dt className="text-muted-foreground">Dépenses</dt>
              <dd className="text-foreground">{spending.join(" · ")}</dd>
            </div>
          ) : null}
        </dl>
      ) : null}
      {data.history.length > 0 ? (
        <details className="text-xs">
          <summary className="cursor-pointer text-muted-foreground hover:text-foreground">Historique</summary>
          <ul className="mt-2 grid gap-1">
            {data.history.map((line) => (
              <li className="flex items-center justify-between gap-3" key={`${line.reason}-${line.at}-${line.label}`}>
                <span className="min-w-0 truncate text-foreground">
                  {lineLabel(line)}
                  <span className="ml-2 text-muted-foreground">{dateFormatter.format(new Date(line.at))}</span>
                </span>
                <PelleAmount amount={line.amount} className="shrink-0 font-medium" signed />
              </li>
            ))}
          </ul>
        </details>
      ) : null}
    </section>
  );
}
