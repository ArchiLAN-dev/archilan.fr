"use client";

import type { ReactNode } from "react";
import { Info, X } from "lucide-react";
import { Popover } from "radix-ui";

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
 * do; the why opens in a small panel anchored to the button, over the page - nothing below moves, so a tight card or
 * banner keeps its layout. Click or Enter opens it, a click outside or Escape closes it; never on hover alone,
 * which a phone does not have. Radix Popover handles the focus, the ARIA and keeping the panel on screen.
 */
export function InfoHint({ children, hint, label = "Pourquoi ?", className }: Props) {
  return (
    <p className={cn("flex items-start gap-1", className)}>
      <span className="min-w-0">{children}</span>
      <Popover.Root>
        <Popover.Trigger asChild>
          <button
            aria-label={label}
            className="-my-3 -mr-3 inline-flex size-11 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:text-foreground data-[state=open]:text-accent-text"
            type="button"
          >
            <Info aria-hidden className="size-4" />
          </button>
        </Popover.Trigger>
        <Popover.Portal>
          <InfoHintPanel label={label}>{hint}</InfoHintPanel>
        </Popover.Portal>
      </Popover.Root>
    </p>
  );
}

/** The floating panel of {@link InfoHint} (exported for the tests). */
export function InfoHintPanel({ children, label }: { children: ReactNode; label: string }) {
  return (
    <Popover.Content
      align="end"
      aria-label={label}
      className="z-50 w-[min(20rem,calc(100vw-2rem))] rounded-xl border border-border bg-surface p-3 pr-9 text-[13px] leading-6 text-muted-foreground shadow-xl outline-none animate-in fade-in-0 zoom-in-95"
      collisionPadding={16}
      side="bottom"
      sideOffset={4}
    >
      {children}
      <Popover.Close
        aria-label="Fermer"
        className="absolute right-1 top-1 inline-flex size-8 items-center justify-center rounded-full text-muted-foreground hover:text-foreground"
      >
        <X aria-hidden className="size-4" />
      </Popover.Close>
      <Popover.Arrow className="fill-[color:var(--color-border)]" />
    </Popover.Content>
  );
}
