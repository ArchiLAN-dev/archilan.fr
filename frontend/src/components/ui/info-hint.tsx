"use client";

import { useId, useState, type ReactNode } from "react";
import { Info } from "lucide-react";

import { cn } from "@/lib/utils";

type Props = {
  /** What the reader needs to act, always shown. */
  children: ReactNode;
  /** The technical « why », shown only on demand. */
  hint: ReactNode;
  /** The button's name for screen readers. */
  label?: string;
  className?: string;
};

/**
 * Story 33.28: a sentence with its technical explanation behind an « i » button. The short sentence says what to
 * do; the why unfolds under it on click or Enter - never on hover alone, which a phone does not have. Closed by
 * default, not remembered.
 */
export function InfoHint({ children, hint, label = "Pourquoi ?", className }: Props) {
  const [open, setOpen] = useState(false);
  return (
    <InfoHintView className={className} hint={hint} label={label} onToggle={() => setOpen((value) => !value)} open={open}>
      {children}
    </InfoHintView>
  );
}

/** The drawing of {@link InfoHint}, given whether it is open (rendered alone by the tests). */
export function InfoHintView({ children, hint, label = "Pourquoi ?", className, open, onToggle }: Props & { open: boolean; onToggle: () => void }) {
  const id = useId();
  return (
    <div className={cn("grid gap-1.5", className)}>
      <p className="flex items-start gap-1">
        <span className="min-w-0">{children}</span>
        <button
          aria-controls={id}
          aria-expanded={open}
          aria-label={label}
          className={cn(
            "-my-3 -mr-3 inline-flex size-11 shrink-0 items-center justify-center rounded-full transition-colors hover:text-foreground",
            open ? "text-accent-text" : "text-muted-foreground",
          )}
          onClick={onToggle}
          type="button"
        >
          <Info aria-hidden className="size-4" />
        </button>
      </p>
      <div
        className={cn("rounded-lg border border-border bg-surface-2 px-3 py-2 text-[13px] leading-6 text-muted-foreground", open ? "" : "hidden")}
        id={id}
      >
        {hint}
      </div>
    </div>
  );
}
