"use client";

import { Info } from "lucide-react";
import { useId, useLayoutEffect, useRef, useState } from "react";

type Props = {
  /** The help text shown in the popover. */
  text: string;
  /** Accessible label for the trigger (defaults to "Aide"). */
  label?: string;
};

/**
 * Small ⓘ trigger that reveals a short help text on hover, on keyboard focus, and on tap/click.
 * Accessible: the trigger is a button with an aria-label; the popover is role="tooltip" referenced
 * via aria-describedby while open.
 *
 * The popover is positioned with `position: fixed` and clamped to the viewport (measured on open in
 * a layout effect, before paint), so it can never cross the right/left edge and push the document
 * sideways — which previously caused a horizontal scroll on narrow screens. Position is applied
 * imperatively on the node (not via state) to avoid a cascading re-render.
 */
export function InfoTooltip({ text, label = "Aide" }: Props) {
  const [open, setOpen] = useState(false);
  const btnRef = useRef<HTMLButtonElement>(null);
  const tipRef = useRef<HTMLSpanElement>(null);
  const id = useId();

  useLayoutEffect(() => {
    if (!open) {
      return;
    }
    const btn = btnRef.current;
    const tip = tipRef.current;
    if (!btn || !tip) {
      return;
    }
    const rect = btn.getBoundingClientRect();
    const margin = 8;
    const width = tip.offsetWidth;
    const centered = rect.left + rect.width / 2 - width / 2;
    const left = Math.max(margin, Math.min(centered, window.innerWidth - width - margin));
    tip.style.left = `${left}px`;
    tip.style.top = `${rect.bottom + 4}px`;
    tip.style.visibility = "visible";
  }, [open]);

  return (
    <span className="inline-flex align-middle">
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
        ref={btnRef}
        type="button"
      >
        <Info aria-hidden className="size-3.5" />
      </button>
      {open ? (
        <span
          className="fixed z-50 w-60 max-w-[calc(100vw-16px)] rounded-md border border-border bg-surface px-3 py-2 text-xs font-normal leading-snug text-muted-foreground shadow-lg"
          id={id}
          ref={tipRef}
          role="tooltip"
          // Rendered hidden in-viewport so its width is measurable without ever overflowing; the
          // layout effect clamps it to the viewport and reveals it, before paint (no flash/scroll).
          style={{ left: 8, top: 0, visibility: "hidden" }}
        >
          {text}
        </span>
      ) : null}
    </span>
  );
}
