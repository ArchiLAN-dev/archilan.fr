"use client";

import { Search, X } from "lucide-react";
import { useEffect, useId, useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";

import { searchUsersForMembership, type UserSearchResult } from "./admin-membership-api";

/**
 * Picks a member by email or display name, from the admin user directory. Shared by the membership dashboard and,
 * since story 5.10, the admin who registers a member on their behalf.
 */
export function UserCombobox({
  selected,
  onSelect,
}: {
  selected: UserSearchResult | null;
  onSelect: (user: UserSearchResult | null) => void;
}) {
  const inputId = useId();
  const [query, setQuery] = useState("");
  const [debouncedQuery, setDebouncedQuery] = useState("");
  const [open, setOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    const t = setTimeout(() => setDebouncedQuery(query), 250);
    return () => clearTimeout(t);
  }, [query]);

  useEffect(() => {
    function onPointerDown(e: PointerEvent) {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener("pointerdown", onPointerDown);
    return () => document.removeEventListener("pointerdown", onPointerDown);
  }, []);

  const { data: results = [], isFetching } = useQuery({
    queryKey: ["admin-user-search", debouncedQuery],
    queryFn: () => searchUsersForMembership(debouncedQuery),
    enabled: debouncedQuery.trim().length >= 2,
    staleTime: 60_000,
  });

  if (selected) {
    return (
      <div className="flex min-h-11 items-center justify-between border border-accent/60 bg-surface px-3">
        <div>
          <p className="text-sm font-semibold text-foreground">{selected.email}</p>
          {selected.displayName && (
            <p className="text-xs text-muted-foreground">{selected.displayName}</p>
          )}
        </div>
        <button
          aria-label="Effacer la sélection"
          className="ml-2 shrink-0 text-muted-foreground transition-colors hover:text-foreground"
          type="button"
          onClick={() => { onSelect(null); setQuery(""); setTimeout(() => inputRef.current?.focus(), 0); }}
        >
          <X aria-hidden="true" className="size-4" />
        </button>
      </div>
    );
  }

  const showDropdown = open && query.trim().length >= 2;

  return (
    <div ref={containerRef} className="relative">
      <span className="flex min-h-11 items-center gap-2 border border-border bg-background px-3 focus-within:border-accent">
        <Search aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
        <input
          ref={inputRef}
          aria-autocomplete="list"
          aria-controls={showDropdown ? `${inputId}-listbox` : undefined}
          aria-expanded={showDropdown}
          autoComplete="off"
          className="min-w-0 flex-1 bg-transparent outline-none placeholder:text-muted-foreground"
          id={inputId}
          placeholder="Rechercher par email ou pseudo…"
          role="combobox"
          type="search"
          value={query}
          onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
          onFocus={() => setOpen(true)}
        />
      </span>

      {showDropdown && (
        <div
          className="absolute top-full z-50 mt-px w-full border border-border bg-background shadow-lg"
          id={`${inputId}-listbox`}
          role="listbox"
        >
          {isFetching && <p className="px-3 py-2.5 text-sm text-muted-foreground">Recherche…</p>}
          {!isFetching && results.length === 0 && (
            <p className="px-3 py-2.5 text-sm text-muted-foreground">Aucun utilisateur trouvé.</p>
          )}
          {!isFetching && results.map((user) => (
            <button
              aria-selected={false}
              className="flex w-full flex-col px-3 py-2.5 text-left transition-colors hover:bg-surface focus:bg-surface focus:outline-none"
              key={user.id}
              role="option"
              type="button"
              onClick={() => { onSelect(user); setQuery(""); setOpen(false); }}
            >
              <span className="text-sm font-medium text-foreground">{user.email}</span>
              {user.displayName && <span className="text-xs text-muted-foreground">{user.displayName}</span>}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
