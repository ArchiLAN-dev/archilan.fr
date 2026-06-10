import { ChevronDown, SlidersHorizontal } from "lucide-react";
import type { ReactNode } from "react";

/**
 * Collapsible "advanced configuration" panel (native <details>) with a leading
 * config icon and a chevron that rotates when open. Pure CSS - no client JS.
 */
export function CollapsibleConfigPanel({
  title,
  children,
  defaultOpen = false,
}: {
  title: string;
  children: ReactNode;
  defaultOpen?: boolean;
}) {
  return (
    <details className="group rounded-xl border border-border bg-surface" open={defaultOpen}>
      <summary className="flex cursor-pointer list-none items-center gap-2.5 px-5 py-3 text-sm font-semibold text-foreground [&::-webkit-details-marker]:hidden">
        <span className="grid size-7 place-items-center rounded-lg bg-accent/10 text-accent-text">
          <SlidersHorizontal aria-hidden className="size-4" />
        </span>
        <span>{title}</span>
        <ChevronDown
          aria-hidden
          className="ml-auto size-4 text-muted-foreground transition-transform duration-200 group-open:rotate-180"
        />
      </summary>
      <div className="border-t border-border p-5">{children}</div>
    </details>
  );
}
