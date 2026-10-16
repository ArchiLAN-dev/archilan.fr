"use client";

import { useState } from "react";
import { AlertTriangle, Check, Clock, Copy, FileQuestion, FileWarning, FileX, ListX, SlidersHorizontal, type LucideIcon } from "lucide-react";

import {
  classifyGenerationFailure,
  generationFailureCopy,
  withoutSlotPrefix,
  type GenerationFailureAudience,
  type GenerationFailureKind,
} from "./generation-failure";

const ICONS: Record<GenerationFailureKind, LucideIcon> = {
  required_file: FileWarning,
  missing_file: FileQuestion,
  invalid_yaml: FileX,
  invalid_option: SlidersHorizontal,
  fill: ListX,
  timeout: Clock,
  unknown: AlertTriangle,
};

type Props = {
  /** The failure as the API summarised it; empty when it gave nothing. */
  message: string;
  audience: GenerationFailureAudience;
  /** The slot the failure belongs to, when known. */
  slotName?: string | null;
  /** The raw log, folded under « Journal technique ». */
  log?: string | null;
  /** Inside a card that already frames it: no box of its own. */
  bare?: boolean;
};

/**
 * Story 38.16: one failed generation, the same way on every screen - what kind of failure, for which slot, the
 * message, what to do about it, and the raw log only on demand.
 */
export function GenerationFailureNotice({ message, audience, slotName = null, log = null, bare = false }: Props) {
  const text = withoutSlotPrefix(message.trim());
  const kind = classifyGenerationFailure(text);
  const { title, advice } = generationFailureCopy(kind, audience);
  const Icon = ICONS[kind];

  return (
    <div className={bare ? "grid gap-1.5" : "grid gap-1.5 rounded-lg border border-danger/40 bg-danger/5 p-3"} role="status">
      <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-semibold text-danger">
        <Icon aria-hidden className="size-4 shrink-0" />
        {title}
        {slotName !== null && slotName !== "" ? (
          <span className="rounded-full border border-border px-2 py-0.5 text-xs font-medium text-foreground">Slot « {slotName} »</span>
        ) : null}
      </p>
      {text !== "" ? <p className="break-words text-sm text-foreground">{text}</p> : null}
      <p className="text-xs text-muted-foreground">{advice}</p>
      {log !== null && log.trim() !== "" ? <TechnicalLog log={log} /> : null}
    </div>
  );
}

/** The raw log of a failure, folded, with a copy button for the support. */
export function TechnicalLog({ log }: { log: string }) {
  const [copied, setCopied] = useState(false);

  async function copy(): Promise<void> {
    try {
      await navigator.clipboard.writeText(log);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // A browser that refuses the clipboard still shows the log to select by hand.
    }
  }

  return (
    <details className="text-xs">
      <summary className="cursor-pointer font-semibold text-muted-foreground hover:text-foreground">Journal technique</summary>
      <div className="mt-2 grid gap-1.5">
        <button
          className="inline-flex w-fit items-center gap-1 rounded border border-border px-2 py-1 text-xs font-semibold text-foreground hover:border-accent"
          onClick={() => void copy()}
          type="button"
        >
          {copied ? <Check aria-hidden className="size-3.5" /> : <Copy aria-hidden className="size-3.5" />}
          {copied ? "Copié" : "Copier"}
        </button>
        <pre className="max-h-64 overflow-auto whitespace-pre-wrap break-all rounded border border-border bg-background p-2 font-mono text-[11px] leading-relaxed text-muted-foreground">
          {log}
        </pre>
      </div>
    </details>
  );
}
