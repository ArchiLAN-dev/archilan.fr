"use client";

import { useEffect, useState, type ReactNode } from "react";
import { ChevronDown, Search, SlidersHorizontal, X } from "lucide-react";
import { Popover } from "radix-ui";

import { Switch } from "@/components/switch";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";

import type { Chip, Option } from "./moderation-filters";

const SEARCH_DEBOUNCE_MS = 300;

/**
 * The moderation tabs' toolbar (story 39.12): search first, a filters button counting what is on, the sort on
 * the side; then the active filters as removable chips and the number of results. Both tabs use it, so
 * reports and contributions read the same way.
 */
export function ModerationToolbar<S extends string, K extends string>({
  search,
  searchPlaceholder,
  onSearch,
  filterCount,
  filters,
  sort,
  sortOptions,
  onSort,
  chips,
  onRemoveChip,
  onReset,
  resultLabel,
}: {
  search: string;
  searchPlaceholder: string;
  onSearch: (search: string) => void;
  filterCount: number;
  /** The filter groups, shown in the panel the "Filtres" button opens. */
  filters: ReactNode;
  sort: S;
  sortOptions: Option<S>[];
  onSort: (sort: S) => void;
  chips: Chip<K>[];
  onRemoveChip: (key: K) => void;
  onReset: () => void;
  /** "12 signalements", or null while loading. */
  resultLabel: string | null;
}) {
  return (
    <div className="grid gap-3">
      <div className="flex flex-wrap items-center gap-2">
        <SearchField onCommit={onSearch} placeholder={searchPlaceholder} value={search} />
        <div className="flex flex-1 items-center justify-between gap-2 sm:flex-none">
          <FiltersButton count={filterCount} onReset={onReset}>
            {filters}
          </FiltersButton>
          <SortSelect onChange={onSort} options={sortOptions} value={sort} />
        </div>
      </div>

      {chips.length > 0 ? (
        <div className="flex flex-wrap items-center gap-2">
          {chips.map((chip) => (
            <button
              aria-label={`Retirer le filtre ${chip.label}`}
              className="inline-flex min-h-7 items-center gap-1 rounded-full border border-accent/40 bg-accent/10 px-2.5 text-xs font-semibold text-foreground transition-colors hover:border-accent"
              key={chip.key}
              onClick={() => onRemoveChip(chip.key)}
              type="button"
            >
              {chip.label}
              <X aria-hidden className="size-3.5" />
            </button>
          ))}
          <button className="text-xs font-semibold text-muted-foreground underline-offset-2 hover:text-foreground hover:underline" onClick={onReset} type="button">
            Réinitialiser
          </button>
        </div>
      ) : null}

      {resultLabel !== null ? <p className="text-sm text-muted-foreground">{resultLabel}</p> : null}
    </div>
  );
}

/**
 * The search box keeps what is typed and hands it over after a pause; a query changed from outside (a
 * chip removed, a reset, the back button) replaces the typed text.
 */
function SearchField({ value, placeholder, onCommit }: { value: string; placeholder: string; onCommit: (search: string) => void }) {
  const [input, setInput] = useState(value);
  const [committed, setCommitted] = useState(value);

  if (value !== committed) {
    setCommitted(value);
    if (value !== input.trim()) setInput(value);
  }

  useEffect(() => {
    const trimmed = input.trim();
    if (trimmed === committed) return;
    const handle = setTimeout(() => onCommit(trimmed), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(handle);
  }, [input, committed, onCommit]);

  return (
    <label className="relative w-full min-w-0 sm:w-auto sm:flex-1">
      <span className="sr-only">Rechercher</span>
      <Search aria-hidden className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
      <input
        className="min-h-10 w-full rounded-lg border border-border bg-background pl-9 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none"
        onChange={(event) => setInput(event.target.value)}
        placeholder={placeholder}
        type="search"
        value={input}
      />
    </label>
  );
}

function FiltersButton({ count, onReset, children }: { count: number; onReset: () => void; children: ReactNode }) {
  return (
    <Popover.Root>
      <Popover.Trigger className={cn(buttonVariants({ variant: "secondary" }), "min-h-10")}>
        <SlidersHorizontal aria-hidden className="size-4" />
        Filtres
        {count > 0 ? <span className="inline-flex min-w-5 justify-center rounded-full bg-accent px-1.5 text-xs font-bold text-white">{count}</span> : null}
      </Popover.Trigger>
      <Popover.Portal>
        <Popover.Content
          align="start"
          className="z-50 grid w-[min(22rem,calc(100vw-2rem))] gap-4 rounded-xl border border-border bg-surface p-4 shadow-xl focus:outline-none"
          sideOffset={8}
        >
          {children}
          <div className="flex justify-between border-t border-border pt-3">
            <button className="text-sm font-semibold text-muted-foreground hover:text-foreground" onClick={onReset} type="button">
              Réinitialiser les filtres
            </button>
            <Popover.Close className={buttonVariants({ variant: "primary" })}>Fermer</Popover.Close>
          </div>
        </Popover.Content>
      </Popover.Portal>
    </Popover.Root>
  );
}

function SortSelect<S extends string>({ value, options, onChange }: { value: S; options: Option<S>[]; onChange: (sort: S) => void }) {
  return (
    <label className="relative">
      <span className="sr-only">Trier par</span>
      <select
        className="min-h-10 appearance-none rounded-lg border border-border bg-background pl-3 pr-9 text-sm font-semibold text-foreground focus:border-accent focus:outline-none"
        onChange={(event) => {
          const next = options.find((option) => option.value === event.target.value);
          if (next !== undefined) onChange(next.value);
        }}
        value={value}
      >
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
      <ChevronDown aria-hidden className="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
    </label>
  );
}

/** Mutually exclusive choices as a row of buttons: the status, or a group of the filter panel. */
export function SegmentedControl<T extends string>({
  label,
  options,
  value,
  onChange,
  size = "md",
}: {
  label: string;
  options: Option<T>[];
  value: T;
  onChange: (value: T) => void;
  size?: "sm" | "md";
}) {
  return (
    <div aria-label={label} className="flex flex-wrap gap-1.5" role="radiogroup">
      {options.map((option) => {
        const checked = option.value === value;
        return (
          <button
            aria-checked={checked}
            className={cn(
              "rounded-full border font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60",
              size === "sm" ? "min-h-8 px-3 text-xs" : "min-h-9 px-3.5 text-sm",
              checked ? "border-accent bg-accent/15 text-foreground" : "border-border text-muted-foreground hover:border-accent hover:text-foreground",
            )}
            key={option.value}
            onClick={() => onChange(option.value)}
            role="radio"
            type="button"
          >
            {option.label}
          </button>
        );
      })}
    </div>
  );
}

/** One group of the filter panel: its name above its choices. */
export function FilterGroup<T extends string>({ label, options, value, onChange }: { label: string; options: Option<T>[]; value: T; onChange: (value: T) => void }) {
  return (
    <div className="grid gap-2">
      <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</p>
      <SegmentedControl label={label} onChange={onChange} options={options} size="sm" value={value} />
    </div>
  );
}

export function FilterToggle({ label, checked, onChange }: { label: string; checked: boolean; onChange: (checked: boolean) => void }) {
  return (
    <div className="flex items-center justify-between gap-3">
      <span className="text-sm font-medium text-foreground">{label}</span>
      <Switch ariaLabel={label} checked={checked} onChange={onChange} />
    </div>
  );
}
