"use client";

import { useId, useMemo, useRef, useState } from "react";

const INPUT_CLS =
  "min-h-9 flex-1 rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent disabled:cursor-not-allowed disabled:opacity-60";

const MIN_CHARS = 2;
const MAX_SUGGESTIONS = 50;

type Props = {
  value: string;
  onChange: (value: string) => void;
  /** null = no location data for this game -> plain text input, no suggestions (graceful degradation). */
  suggestions: string[] | null;
  disabled?: boolean;
  placeholder?: string;
};

/**
 * Free-text input for a location name with a static-suggestion dropdown (story 4.14).
 *
 * The suggestions are the apworld's static location list - a convenience hint, NOT a constraint: any
 * string is accepted (Enter/blur keep the typed value), and when `suggestions` is null the field is a
 * plain text input. The real per-generation location set is options-dependent and unknown at config time,
 * so we never validate against the list.
 */
export function LocationAutocompleteInput({
  value,
  onChange,
  suggestions,
  disabled = false,
  placeholder = "élément",
}: Props) {
  const [open, setOpen] = useState(false);
  const [highlight, setHighlight] = useState(-1);
  const blurTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const listboxId = useId();

  const matches = useMemo(() => {
    if (!suggestions || value.trim().length < MIN_CHARS) return [];
    const needle = value.trim().toLowerCase();
    const out: string[] = [];
    for (const s of suggestions) {
      if (s.toLowerCase().includes(needle)) {
        out.push(s);
        if (out.length >= MAX_SUGGESTIONS) break;
      }
    }
    return out;
  }, [suggestions, value]);

  const showList = open && !disabled && matches.length > 0;

  function select(name: string) {
    onChange(name);
    setOpen(false);
    setHighlight(-1);
  }

  // No location data -> plain input, identical to the pre-4.14 behaviour.
  if (suggestions === null) {
    return (
      <input
        className={INPUT_CLS}
        disabled={disabled}
        placeholder={placeholder}
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
    );
  }

  return (
    <div className="relative flex-1">
      <input
        aria-autocomplete="list"
        aria-controls={listboxId}
        aria-expanded={showList}
        autoComplete="off"
        className={`${INPUT_CLS} w-full`}
        disabled={disabled}
        placeholder={placeholder}
        role="combobox"
        value={value}
        onBlur={() => {
          // Delay so a suggestion click registers before the list unmounts.
          blurTimer.current = setTimeout(() => setOpen(false), 120);
        }}
        onChange={(e) => {
          onChange(e.target.value);
          setOpen(true);
          setHighlight(-1);
        }}
        onFocus={() => setOpen(true)}
        onKeyDown={(e) => {
          if (!showList) return;
          if (e.key === "ArrowDown") {
            e.preventDefault();
            setHighlight((h) => (h + 1) % matches.length);
          } else if (e.key === "ArrowUp") {
            e.preventDefault();
            setHighlight((h) => (h <= 0 ? matches.length - 1 : h - 1));
          } else if (e.key === "Enter") {
            if (highlight >= 0 && highlight < matches.length) {
              e.preventDefault();
              select(matches[highlight]);
            }
          } else if (e.key === "Escape") {
            setOpen(false);
            setHighlight(-1);
          }
        }}
      />
      {showList && (
        <ul
          className="absolute left-0 right-0 top-full z-20 mt-1 max-h-56 overflow-auto rounded border border-border bg-surface py-1 shadow-lg"
          id={listboxId}
          role="listbox"
          // Keep focus on the input so onBlur doesn't fire before the click handler.
          onMouseDown={(e) => {
            e.preventDefault();
            if (blurTimer.current) clearTimeout(blurTimer.current);
          }}
        >
          {matches.map((name, i) => (
            <li key={name}>
              <button
                aria-selected={i === highlight}
                className={`block w-full cursor-pointer truncate px-3 py-1.5 text-left text-sm ${
                  i === highlight ? "bg-accent/15 text-foreground" : "text-muted-foreground hover:bg-accent/10"
                }`}
                role="option"
                type="button"
                onClick={() => select(name)}
                onMouseEnter={() => setHighlight(i)}
              >
                {name}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
