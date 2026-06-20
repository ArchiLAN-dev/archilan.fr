"use client";

import { Clock, Gamepad2, X } from "lucide-react";

export type FilterTokenIcon = "clock" | "gamepad";

/** A group of selectable filter options, rendered as an <optgroup> in the picker. */
export type FilterGroup = {
  label: string;
  options: { value: string; label: string }[];
};

/** An active filter rendered as a removable chip next to the picker. */
export type ActiveFilterToken = {
  key: string;
  label: string;
  icon?: FilterTokenIcon;
  remove: () => void;
};

/**
 * Token-style filter bar: a single <select> lists the filters not yet active (grouped via
 * <optgroup>); picking one calls `onAdd(value)`, which the caller turns into an active token.
 * Active tokens render as removable chips; "Tout effacer" calls `onClear`. Self-hides when there
 * is nothing to show. Search/sort controls live outside this component.
 */
export function FilterTokenBar({
  groups,
  activeTokens,
  hasActiveFilters,
  onAdd,
  onClear,
}: {
  groups: FilterGroup[];
  activeTokens: ActiveFilterToken[];
  hasActiveFilters: boolean;
  onAdd: (value: string) => void;
  onClear: () => void;
}) {
  const hasAnyOption = groups.some((g) => g.options.length > 0);
  if (!hasAnyOption && activeTokens.length === 0) return null;

  return (
    <div className="flex flex-wrap items-center gap-2">
      <select
        aria-label="Ajouter un filtre"
        className="min-h-9 cursor-pointer rounded-full border border-border bg-surface px-3 text-sm font-medium text-muted-foreground transition-colors hover:border-accent hover:text-foreground focus:border-accent focus:outline-none disabled:cursor-not-allowed disabled:opacity-50"
        disabled={!hasAnyOption}
        onChange={(e) => onAdd(e.target.value)}
        value=""
      >
        <option disabled value="">
          {hasAnyOption ? "+ Ajouter un filtre…" : "Tous les filtres actifs"}
        </option>
        {groups.map((group) =>
          group.options.length > 0 ? (
            <optgroup key={group.label} label={group.label}>
              {group.options.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </optgroup>
          ) : null,
        )}
      </select>

      {activeTokens.map((token) => (
        <span
          className="inline-flex min-h-9 items-center gap-1.5 rounded-full border border-accent bg-accent/15 pl-3 pr-1.5 text-sm font-medium text-accent-text"
          key={token.key}
        >
          {token.icon === "clock" && <Clock aria-hidden className="size-3.5" />}
          {token.icon === "gamepad" && <Gamepad2 aria-hidden className="size-3.5" />}
          {token.label}
          <button
            aria-label={`Retirer le filtre ${token.label}`}
            className="inline-flex size-5 cursor-pointer items-center justify-center rounded-full text-accent-text/70 transition-colors hover:bg-accent/25 hover:text-accent-text"
            onClick={token.remove}
            type="button"
          >
            <X aria-hidden className="size-3" />
          </button>
        </span>
      ))}

      {hasActiveFilters && (
        <button
          className="ml-auto inline-flex min-h-9 cursor-pointer items-center gap-1.5 rounded-full px-2.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
          onClick={onClear}
          type="button"
        >
          <X aria-hidden className="size-3.5" />
          Tout effacer
        </button>
      )}
    </div>
  );
}
