"use client";

import { Info } from "lucide-react";
import { useId, useState } from "react";

type Props = {
  /** The help text shown in the popover. */
  text: string;
  /** Accessible label for the trigger (defaults to "Aide"). */
  label?: string;
};

/**
 * Small ⓘ trigger that reveals a short help text on hover, on keyboard focus, and on tap/click.
 * Accessible: the trigger is a button with an aria-label; the popover is role="tooltip" and is
 * referenced via aria-describedby while open. No external dependency, no portal — positioned
 * relative to the inline trigger.
 */
export function InfoTooltip({ text, label = "Aide" }: Props) {
  const [open, setOpen] = useState(false);
  const id = useId();

  return (
    <span className="relative inline-flex align-middle">
      <button
        aria-describedby={open ? id : undefined}
        aria-expanded={open}
        aria-label={label}
        className="inline-flex text-muted-foreground/60 transition-colors hover:text-accent-text focus-visible:text-accent-text focus-visible:outline-none"
        onBlur={() => setOpen(false)}
        onClick={() => setOpen((o) => !o)}
        onFocus={() => setOpen(true)}
        onMouseEnter={() => setOpen(true)}
        onMouseLeave={() => setOpen(false)}
        type="button"
      >
        <Info aria-hidden className="size-3.5" />
      </button>
      {open ? (
        <span
          className="absolute left-1/2 top-[calc(100%+4px)] z-50 w-60 max-w-[min(15rem,80vw)] -translate-x-1/2 rounded-md border border-border bg-surface px-3 py-2 text-xs font-normal leading-snug text-muted-foreground shadow-lg"
          id={id}
          role="tooltip"
        >
          {text}
        </span>
      ) : null}
    </span>
  );
}
