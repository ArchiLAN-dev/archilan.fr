"use client";

import { useEffect, useState, type ReactNode } from "react";
import { Search } from "lucide-react";

import { Switch } from "@/components/switch";
import { SelectField } from "@/components/ui/select-field";
import { cn } from "@/lib/utils";

import type { Option } from "./moderation-filters";

const SEARCH_DEBOUNCE_MS = 300;

/**
 * The moderation tabs' toolbar (story 39.12): search and sort, then the filters, a reset and the number of
 * results. Story 39.13: the filters are dropdowns always on the page - what is filtered reads at a glance,
 * with no panel to open. Both tabs use it, so reports and contributions read the same way.
 */
export function ModerationToolbar<S extends string>({
  search,
  searchPlaceholder,
  onSearch,
  filters,
  active,
  onReset,
  sort,
  sortOptions,
  onSort,
  resultLabel,
}: {
  search: string;
  searchPlaceholder: string;
  onSearch: (search: string) => void;
  /** The tab's filter dropdowns (and toggles), laid out in a row. */
  filters: ReactNode;
  /** A filter or a search narrows the list: the reset shows. */
  active: boolean;
  onReset: () => void;
  sort: S;
  sortOptions: Option<S>[];
  onSort: (sort: S) => void;
  /** "12 signalements", or null while loading. */
  resultLabel: string | null;
}) {
  return (
    <div className="grid gap-3">
      <div className="flex flex-wrap items-center gap-2">
        <SearchField onCommit={onSearch} placeholder={searchPlaceholder} value={search} />
        <FilterSelect defaultValue={sort} label="Tri" onChange={onSort} options={sortOptions} value={sort} />
      </div>

      <div className="grid grid-cols-2 items-center gap-2 sm:flex sm:flex-wrap">
        {filters}
        {active ? (
          <button
            className="col-span-2 justify-self-start px-1 text-sm font-semibold text-muted-foreground underline-offset-2 hover:text-foreground hover:underline sm:ml-auto"
            onClick={onReset}
            type="button"
          >
            Réinitialiser
          </button>
        ) : null}
      </div>

      {resultLabel !== null ? <p className="text-sm text-muted-foreground">{resultLabel}</p> : null}
    </div>
  );
}

/**
 * The search box keeps what is typed and hands it over after a pause; a query changed from outside (a
 * reset, the back button) replaces the typed text.
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

/**
 * A filter dropdown on the site's own `SelectField` (a native select opens a grey list that ignores the theme on
 * Windows). It stands out while it filters, i.e. while its value is not the default.
 */
export function FilterSelect<T extends string>({
  label,
  options,
  value,
  defaultValue,
  onChange,
}: {
  label: string;
  options: Option<T>[];
  value: T;
  defaultValue: T;
  onChange: (value: T) => void;
}) {
  return <SelectField highlighted={value !== defaultValue} label={label} onChange={onChange} options={options} value={value} />;
}

/** An on/off filter, in the same row and frame as the dropdowns. */
export function FilterToggle({ label, checked, onChange }: { label: string; checked: boolean; onChange: (checked: boolean) => void }) {
  return (
    <div
      className={cn(
        "flex min-h-10 items-center justify-between gap-3 rounded-lg border px-3 text-sm",
        checked ? "border-accent-text/70 bg-accent/20" : "border-border bg-background",
      )}
    >
      <span className="text-muted-foreground">{label}</span>
      <Switch ariaLabel={label} checked={checked} onChange={onChange} />
    </div>
  );
}

/** Mutually exclusive choices as a row of buttons: the status of the list. */
export function SegmentedControl<T extends string>({
  label,
  options,
  value,
  onChange,
}: {
  label: string;
  options: Option<T>[];
  value: T;
  onChange: (value: T) => void;
}) {
  return (
    <div aria-label={label} className="flex flex-wrap gap-1.5" role="radiogroup">
      {options.map((option) => {
        const checked = option.value === value;
        return (
          <button
            aria-checked={checked}
            className={cn(
              "min-h-9 rounded-full border px-3.5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60",
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
