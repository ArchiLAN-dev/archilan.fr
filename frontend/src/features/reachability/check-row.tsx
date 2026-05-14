import { Check, Lightbulb, Loader2, X } from "lucide-react";
import { useState } from "react";

import type { CheckEntry } from "./types";

export function HintButton({
  onHint,
  free = false,
  hintCost = 0,
}: {
  onHint: () => Promise<void>;
  free?: boolean;
  hintCost?: number;
}) {
  const [status, setStatus] = useState<"idle" | "confirming" | "loading" | "ok" | "err">("idle");

  function requestHint() {
    if (status !== "idle") return;
    setStatus("confirming");
  }

  function confirm() {
    setStatus("loading");
    onHint().then(
      () => { setStatus("ok"); setTimeout(() => { setStatus("idle"); }, 2000); },
      () => { setStatus("err"); setTimeout(() => { setStatus("idle"); }, 2000); },
    );
  }

  if (status === "confirming") {
    return (
      <div className="flex shrink-0 items-center gap-1">
        <button
          className="inline-flex items-center gap-1 rounded border border-amber-500/40 bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-400 hover:bg-amber-500/20"
          onClick={confirm}
          type="button"
        >
          <Lightbulb className="size-3" />
          {free ? "Gratuit (admin)" : `${hintCost} pts`}
          <span className="text-amber-300/70">· Confirmer</span>
        </button>
        <button
          aria-label="Annuler"
          className="inline-flex items-center rounded border border-border px-1.5 py-0.5 text-xs text-muted-foreground hover:border-danger/40 hover:text-danger"
          onClick={() => { setStatus("idle"); }}
          type="button"
        >
          <X className="size-3" />
        </button>
      </div>
    );
  }

  return (
    <button
      aria-label="Demander un indice"
      className={`inline-flex shrink-0 items-center rounded border px-1.5 py-0.5 transition-colors disabled:opacity-40 ${
        status === "ok"  ? "border-success/40 bg-success/10 text-success" :
        status === "err" ? "border-danger/40 bg-danger/10 text-danger" :
        "border-amber-500/30 bg-amber-500/5 text-amber-400 hover:bg-amber-500/15"
      }`}
      disabled={status !== "idle"}
      onClick={requestHint}
      type="button"
    >
      {status === "loading" ? <Loader2 className="size-3 animate-spin" /> :
       status === "ok"      ? <Check className="size-3" /> :
       status === "err"     ? <X className="size-3" /> :
                              <Lightbulb className="size-3" />}
    </button>
  );
}

export function StatPill({
  label,
  value,
  highlight = false,
}: {
  label: string;
  value: string;
  highlight?: boolean;
}) {
  return (
    <div
      className={`rounded border px-3 py-1.5 text-sm ${
        highlight
          ? "border-success/30 bg-success/10 text-success"
          : "border-border bg-surface text-foreground"
      }`}
    >
      <span className="text-xs text-muted-foreground">{label} </span>
      <span className="font-semibold">{value}</span>
    </div>
  );
}

export function CheckRow({
  check,
  currentSlot,
  variant,
  onHintRequest,
  hintFree = false,
  hintCost = 0,
  hideSpoilers = false,
}: {
  check: CheckEntry;
  currentSlot: number;
  variant: "reachable" | "unreachable";
  onHintRequest?: (locationId: number) => Promise<void>;
  hintFree?: boolean;
  hintCost?: number;
  hideSpoilers?: boolean;
}) {
  const isOwnItem = check.item?.slot === currentSlot;
  return (
    <li className="grid gap-0.5 px-4 py-2.5">
      <div className="flex items-center justify-between gap-2">
        <span className={`text-sm ${variant === "reachable" ? "text-foreground" : "text-muted-foreground"}`}>
          {check.name}
        </span>
        {onHintRequest ? (
          <HintButton free={hintFree} hintCost={hintCost} onHint={() => onHintRequest(check.id)} />
        ) : null}
      </div>
      {!hideSpoilers && check.item ? (
        <span className="flex flex-wrap items-baseline gap-1.5 text-xs text-muted-foreground/70">
          <span>→ {check.item.name}</span>
          {!isOwnItem ? (
            <span className="rounded bg-surface-2 px-1.5 py-0.5 font-mono text-[10px] text-accent-text">
              {check.item.slot_name}
            </span>
          ) : null}
        </span>
      ) : null}
    </li>
  );
}
