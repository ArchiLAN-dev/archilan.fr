"use client";

import { Check } from "lucide-react";

type StepStatus = "done" | "current" | "upcoming";

const LABELS = ["Réservation", "Sélection des jeux", "Récapitulatif & options"] as const;

export function RegistrationStepper({ currentStep }: { currentStep: 0 | 1 | 2 }) {
  const statuses: StepStatus[] = LABELS.map((_, i) =>
    i < currentStep ? "done" : i === currentStep ? "current" : "upcoming",
  );

  return (
    <nav aria-label="Étapes d'inscription" className="w-full">
      <ol className="flex items-start">
        {LABELS.map((label, i) => (
          <li key={label} className={`flex items-start ${i < LABELS.length - 1 ? "flex-1" : ""}`}>
            <div className="flex flex-col items-center">
              <div
                aria-current={statuses[i] === "current" ? "step" : undefined}
                className={[
                  "flex size-9 shrink-0 items-center justify-center rounded-full font-bold transition-all",
                  statuses[i] === "done"
                    ? "bg-success text-white"
                    : statuses[i] === "current"
                      ? "bg-accent text-white shadow-[0_0_0_3px_color-mix(in_oklab,var(--color-accent)_30%,transparent),0_0_0_6px_color-mix(in_oklab,var(--color-accent)_12%,transparent)]"
                      : "border-2 border-border bg-surface text-muted-foreground",
                ].join(" ")}
              >
                {statuses[i] === "done" ? (
                  <Check aria-hidden="true" className="size-4" />
                ) : (
                  <span className="text-sm">{i + 1}</span>
                )}
              </div>
              <p
                className={[
                  "mt-2 max-w-[80px] text-center text-xs font-medium leading-tight sm:max-w-none",
                  statuses[i] === "current"
                    ? "text-foreground"
                    : statuses[i] === "done"
                      ? "text-success"
                      : "text-muted-foreground",
                ].join(" ")}
              >
                {label}
              </p>
            </div>
            {i < LABELS.length - 1 && (
              <div
                className={[
                  "mx-3 mt-4 h-0.5 flex-1 rounded-full transition-colors",
                  statuses[i] === "done" ? "bg-success" : "bg-border",
                ].join(" ")}
              />
            )}
          </li>
        ))}
      </ol>
    </nav>
  );
}
