"use client";

import { CheckCircle2, XCircle } from "lucide-react";

type StepState = "complete" | "active" | "error" | "future";

type SessionStatus =
  | "draft"
  | "validating"
  | "ready"
  | "generating"
  | "generated"
  | "launching"
  | "running"
  | "stopped"
  | "failed"
  | "crashed"
  | "finished";

const STATUS_LABELS: Record<SessionStatus, string> = {
  draft: "Brouillon",
  validating: "Validation",
  ready: "Prêt",
  generating: "Génération",
  generated: "Généré",
  launching: "Lancement",
  running: "En cours",
  stopped: "Arrêté",
  failed: "Échec",
  crashed: "Planté",
  finished: "Terminé",
};

const PIPELINE_STEPS = [
  { label: "Créée" },
  { label: "Validée" },
  { label: "Générée" },
  { label: "En ligne" },
] as const;

function normalizeStatus(status: string): SessionStatus | null {
  return [
    "draft", "validating", "ready", "generating", "generated",
    "launching", "running", "stopped", "failed", "crashed", "finished",
  ].includes(status) ? (status as SessionStatus) : null;
}

function getStepStates(status: string): [StepState, StepState, StepState, StepState] {
  switch (normalizeStatus(status)) {
    case "draft":      return ["complete", "future",   "future",   "future"];
    case "validating": return ["complete", "active",   "future",   "future"];
    case "ready":      return ["complete", "complete", "future",   "future"];
    case "generating": return ["complete", "complete", "active",   "future"];
    case "generated":  return ["complete", "complete", "complete", "future"];
    case "launching":  return ["complete", "complete", "complete", "active"];
    case "running":
    case "stopped":
    case "finished":   return ["complete", "complete", "complete", "complete"];
    case "failed":     return ["complete", "complete", "error",    "future"];
    case "crashed":    return ["complete", "complete", "complete", "error"];
    default:           return ["future",   "future",   "future",   "future"];
  }
}

function getLineStyle(left: StepState, right: StepState, muted: boolean): {
  className: string;
  style?: React.CSSProperties;
} {
  if (muted) return { className: "bg-muted-foreground/30" };
  if (left === "error" || right === "error") return { className: "bg-danger/30" };
  if (left === "complete" && right === "active") return {
    className: "",
    style: {
      backgroundImage: "repeating-linear-gradient(90deg, #1abd8c 0px, #1abd8c 3px, transparent 3px, transparent 11px)",
      backgroundSize: "22px 100%",
      animation: "flow-dots 0.6s linear infinite",
    },
  };
  if (left === "complete" && right === "complete") return { className: "bg-success" };
  if (left === "active") return { className: "animate-pulse bg-accent-warm/30" };
  return { className: "bg-muted-foreground/40" };
}

function getCircleClass(state: StepState, muted: boolean): string {
  if (muted && state === "complete") return "border-muted-foreground/40 bg-surface";
  return {
    complete: "border-success bg-success/10",
    active:   "border-accent-warm bg-accent-warm/20",
    error:    "border-danger bg-danger/10",
    future:   "border-muted-foreground/50 bg-surface-2",
  }[state];
}

function getLabelClass(state: StepState, muted: boolean): string {
  if (muted && state === "complete") return "text-muted-foreground/50";
  return {
    complete: "text-success/80",
    active:   "text-accent-warm",
    error:    "text-danger",
    future:   "text-muted-foreground/60",
  }[state];
}

export function SessionPipelineBar({ status }: { status: string }) {
  const normalized = normalizeStatus(status);
  const steps = getStepStates(status);
  const muted = normalized === "stopped" || normalized === "finished";

  return (
    <div
      aria-label={`Statut : ${normalized ? STATUS_LABELS[normalized] : status}`}
      className="w-full"
      role="status"
    >
      <div className="flex w-full">
        {PIPELINE_STEPS.map((step, i) => {
          const state = steps[i as 0 | 1 | 2 | 3];
          const prevState = i > 0 ? steps[(i - 1) as 0 | 1 | 2] : null;
          const nextState = i < 3 ? steps[(i + 1) as 1 | 2 | 3] : null;
          const isRunning = normalized === "running" && i === 3;

          return (
            <div className="flex flex-1 flex-col items-center" key={step.label}>
              {/* Circle row */}
              <div className="flex w-full items-center">
                {prevState !== null ? (() => {
                  const { className, style } = getLineStyle(prevState, state, muted);
                  return <div className={`h-0.5 flex-1 transition-colors duration-700 ${className}`} style={style} />;
                })() : (
                  <div className="flex-1" />
                )}

                <div className={`flex size-7 shrink-0 items-center justify-center rounded-full border-2 ${getCircleClass(state, muted)}`}>
                  {state === "complete" ? (
                    <CheckCircle2 aria-hidden="true" className={`size-3.5 ${muted ? "text-muted-foreground/50" : "text-success"}`} />
                  ) : state === "active" ? (
                    <span aria-hidden="true" className="size-2 animate-pulse rounded-full bg-accent-warm" />
                  ) : state === "error" ? (
                    <XCircle aria-hidden="true" className="size-3.5 text-danger" />
                  ) : (
                    <span aria-hidden="true" className="size-1.5 rounded-full bg-muted-foreground/40" />
                  )}
                </div>

                {nextState !== null ? (() => {
                  const { className, style } = getLineStyle(state, nextState, muted);
                  return <div className={`h-0.5 flex-1 transition-colors duration-700 ${className}`} style={style} />;
                })() : (
                  <div className="flex-1" />
                )}
              </div>

              {/* Label */}
              <span className={`mt-2 hidden text-center text-[11px] font-medium leading-none sm:block ${getLabelClass(state, muted)}`}>
                {step.label}
                {isRunning ? (
                  <span aria-hidden="true" className="ml-1 inline-block size-1.5 animate-pulse rounded-full bg-success align-middle" />
                ) : null}
              </span>
            </div>
          );
        })}
      </div>
    </div>
  );
}

function miniDotClasses(status: string, index: number): string {
  const normalized = normalizeStatus(status);
  if (normalized === "failed" || normalized === "crashed") return "bg-danger";
  if (normalized === "running" || normalized === "finished") return "bg-success";
  if (normalized === "draft" || normalized === null) return "bg-muted-foreground/30";

  const steps = getStepStates(status);
  const mapped = [steps[0], steps[2], steps[3]][index];
  if (mapped === "complete") return "bg-success";
  if (mapped === "active") return "animate-pulse bg-accent-warm";
  return ["validating", "ready", "generating", "generated", "launching"].includes(normalized)
    ? "animate-pulse bg-accent-warm"
    : "bg-muted-foreground/30";
}

export function SessionPipelineDots({ status }: { status: string }) {
  return (
    <div aria-hidden="true" className="flex items-center gap-1">
      {[0, 1, 2].map((i) => (
        <span className={`size-2 rounded-full ${miniDotClasses(status, i)}`} key={i} />
      ))}
    </div>
  );
}
