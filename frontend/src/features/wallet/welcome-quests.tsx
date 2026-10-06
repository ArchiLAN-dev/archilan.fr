"use client";

import { useQuery } from "@tanstack/react-query";
import { CheckCircle2, Circle } from "lucide-react";
import Link from "next/link";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";
import { PelleAmount } from "./pelle-amount";

/** Story 41.25: one first step of a newcomer, paid once. */
export type WelcomeStep = { key: string; label: string; description: string; reward: number; done: boolean; paid: boolean };

export type WelcomeQuests = { steps: WelcomeStep[]; total: number };

/** Where each step is done. */
const STEP_LINKS: Record<string, { href: string; label: string }> = {
  discord: { href: "/compte/securite", label: "Lier Discord" },
  check: { href: "/parties", label: "Voir les parties" },
  weekly: { href: "/runs-hebdo", label: "Voir l'hebdo" },
  partner: { href: "/parties", label: "Voir les parties" },
  goal: { href: "/parties", label: "Voir les parties" },
};

function isStep(v: unknown): v is WelcomeStep {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "key") &&
    hasStringProp(v, "label") &&
    hasStringProp(v, "description") &&
    hasNumberProp(v, "reward") &&
    hasBooleanProp(v, "done") &&
    hasBooleanProp(v, "paid")
  );
}

export function isWelcomeQuests(v: unknown): v is WelcomeQuests {
  return typeof v === "object" && v !== null && hasNumberProp(v, "total") && "steps" in v && Array.isArray(v.steps) && v.steps.every(isStep);
}

/** The newcomer's first steps, null when there are none to show (an older account, or every step paid). */
export async function fetchMyWelcomeQuests(): Promise<WelcomeQuests | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/me/welcome-quests`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null || !("welcome" in payload)) return null;
    return isWelcomeQuests(payload.welcome) ? payload.welcome : null;
  } catch {
    return null;
  }
}

export function WelcomeQuestsPanel() {
  const { data } = useQuery({ queryKey: ["my-welcome-quests"], queryFn: fetchMyWelcomeQuests, staleTime: DEFAULT_STALE_TIME, retry: false });

  return data ? <WelcomeQuestsView welcome={data} /> : null;
}

/** « Premiers pas » on « Mon portefeuille » (story 41.25): gone once every step is paid. */
export function WelcomeQuestsView({ welcome }: { welcome: WelcomeQuests }) {
  const done = welcome.steps.filter((step) => step.done).length;

  return (
    <section aria-labelledby="welcome-quests" className="grid gap-3 rounded-xl border border-accent/40 bg-accent/5 p-5">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="font-heading text-xl font-semibold text-foreground" id="welcome-quests">
          Premiers pas
        </h2>
        <p className="text-xs text-muted-foreground">
          {done} / {welcome.steps.length} faits · <PelleAmount amount={welcome.total} className="font-semibold text-warning" /> en tout
        </p>
      </div>
      <p className="text-sm text-muted-foreground">Bienvenue ! Chaque premier pas rapporte des pelles, une seule fois.</p>
      <ul className="grid gap-3">
        {welcome.steps.map((step) => {
          const link = STEP_LINKS[step.key];
          return (
            <li className="flex items-start justify-between gap-3 text-sm" key={step.key}>
              <span className="flex min-w-0 items-start gap-2">
                {step.done ? (
                  <CheckCircle2 aria-hidden className="mt-0.5 size-4 shrink-0 text-success" />
                ) : (
                  <Circle aria-hidden className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                )}
                <span className="grid gap-0.5">
                  <span className={`font-medium ${step.done ? "text-foreground" : "text-muted-foreground"}`}>
                    {step.label}
                    <span className="sr-only">{step.done ? " : fait" : " : à faire"}</span>
                  </span>
                  <span className="text-xs text-muted-foreground">
                    {step.description}
                    {step.done && !step.paid ? " Crédité dans quelques minutes." : null}
                    {!step.done && link ? (
                      <>
                        {" "}
                        <Link className="text-accent-text hover:underline" href={link.href}>
                          {link.label}
                        </Link>
                      </>
                    ) : null}
                  </span>
                </span>
              </span>
              <PelleAmount amount={step.reward} className="shrink-0 text-xs font-semibold text-warning" signed />
            </li>
          );
        })}
      </ul>
    </section>
  );
}
