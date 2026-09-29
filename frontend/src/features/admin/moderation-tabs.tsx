"use client";

import { BookOpen, Flag, type LucideIcon } from "lucide-react";

import { cn } from "@/lib/utils";

import type { ModerationTab } from "./moderation-filters";

const TABS: { value: ModerationTab; label: string; icon: LucideIcon }[] = [
  { value: "reports", label: "Signalements", icon: Flag },
  { value: "contributions", label: "Contributions tutoriels", icon: BookOpen },
];

/**
 * The moderation queues as a tab bar (story 39.13): a framed control, each tab with its icon, its label and
 * what waits in it; the current one raised on the frame. Both tabs share the width on a phone.
 */
export function ModerationTabs({
  tab,
  counts,
  onChange,
}: {
  tab: ModerationTab;
  counts: Record<ModerationTab, number | undefined>;
  onChange: (tab: ModerationTab) => void;
}) {
  return (
    <div aria-label="Files de modération" className="flex w-full gap-1 rounded-xl border border-border bg-surface p-1 sm:inline-flex sm:w-auto sm:justify-self-start" role="tablist">
      {TABS.map(({ value, label, icon: Icon }) => {
        const selected = tab === value;
        const count = counts[value];
        return (
          <button
            aria-selected={selected}
            className={cn(
              "inline-flex min-h-10 flex-1 items-center justify-center gap-2 rounded-lg px-4 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60 sm:flex-none",
              selected ? "bg-background text-foreground shadow-sm ring-1 ring-border" : "text-muted-foreground hover:bg-surface-2 hover:text-foreground",
            )}
            key={value}
            onClick={() => onChange(value)}
            role="tab"
            type="button"
          >
            <Icon aria-hidden className={cn("size-4 shrink-0", selected ? "text-accent-text" : undefined)} />
            <span className="truncate">{label}</span>
            {count !== undefined && count > 0 ? (
              <span
                className={cn(
                  "inline-flex min-w-5 justify-center rounded-full px-1.5 text-xs font-bold",
                  selected ? "bg-accent text-white" : "bg-surface-2 text-muted-foreground",
                )}
              >
                {count}
              </span>
            ) : null}
          </button>
        );
      })}
    </div>
  );
}
