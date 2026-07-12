"use client";

import { Gamepad2 } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { searchIgdbGames, type IgdbSearchEntry } from "./admin-igdb-api";

export type IgdbResult = IgdbSearchEntry;

const PAGE_SIZE = 10;

export function IgdbGameSearch({ onSelect }: { onSelect: (result: IgdbResult) => void }) {
  const [query, setQuery] = useState("");
  const [debouncedQuery, setDebouncedQuery] = useState("");
  const [open, setOpen] = useState(false);
  const [page, setPage] = useState(0);

  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function handleMousedown(e: MouseEvent) {
      if (!containerRef.current?.contains(e.target as Node)) setOpen(false);
    }
    document.addEventListener("mousedown", handleMousedown);
    return () => document.removeEventListener("mousedown", handleMousedown);
  }, []);

  useEffect(() => {
    function handleKeydown(e: KeyboardEvent) {
      if (e.key === "Escape") setOpen(false);
    }
    document.addEventListener("keydown", handleKeydown);
    return () => document.removeEventListener("keydown", handleKeydown);
  }, []);

  useEffect(() => () => {
    if (timerRef.current) clearTimeout(timerRef.current);
  }, []);

  // The 300ms debounce feeds the KEY input (debouncedQuery); TanStack's signal subsumes the old
  // AbortController. searchIgdbGames returns null on any failure (never throws), so
  // `data === null` is the error state and - like the old handler - there is no retry.
  const searchQuery = useQuery({
    queryKey: ["admin-igdb-search", debouncedQuery, page * PAGE_SIZE],
    queryFn: ({ signal }) => searchIgdbGames(debouncedQuery, page * PAGE_SIZE, signal),
    enabled: debouncedQuery.trim().length > 0,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    placeholderData: keepPreviousData,
  });
  const results = searchQuery.data?.results ?? [];
  const hasMore = searchQuery.data?.hasMore ?? false;

  function handleQueryChange(q: string) {
    setQuery(q);
    setPage(0);
    if (timerRef.current) clearTimeout(timerRef.current);
    if (!q.trim()) {
      setDebouncedQuery("");
      setOpen(false);
      return;
    }
    timerRef.current = setTimeout(() => {
      setDebouncedQuery(q);
      setOpen(true);
    }, 300);
  }

  function handlePageChange(delta: number) {
    setPage((p) => p + delta);
  }

  function handleSelect(result: IgdbResult) {
    onSelect(result);
    setOpen(false);
    setQuery("");
    setDebouncedQuery("");
    setPage(0);
  }

  const showDropdown = open && query.trim().length > 0;

  return (
    <div ref={containerRef} className="relative">
      <input
        autoComplete="off"
        className="w-full min-h-11 rounded border border-border bg-background px-3 outline-none focus:border-accent"
        placeholder="Rechercher sur IGDB…"
        type="search"
        value={query}
        onChange={(e) => handleQueryChange(e.target.value)}
        onFocus={() => {
          if (query.trim() && (results.length > 0 || searchQuery.isFetching)) setOpen(true);
        }}
      />

      {showDropdown ? (
        <ul className="absolute left-0 right-0 top-full z-50 mt-1 max-h-72 overflow-y-auto rounded border border-border bg-surface shadow-lg">
          {searchQuery.isPending ? (
            <li className="px-4 py-3 text-sm text-muted-foreground">Recherche en cours…</li>
          ) : searchQuery.data === null ? (
            <li className="px-4 py-3 text-sm text-danger">Erreur lors de la recherche IGDB.</li>
          ) : results.length === 0 ? (
            <li className="px-4 py-3 text-sm text-muted-foreground">
              Aucun résultat pour «&nbsp;{query}&nbsp;»
            </li>
          ) : (
            <>
              {results.map((result) => (
                <li key={result.igdbId}>
                  <button
                    className="flex w-full items-center gap-3 px-3 py-2 text-left transition-colors hover:bg-surface-2"
                    type="button"
                    onClick={() => handleSelect(result)}
                  >
                    {result.coverUrl ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img
                        alt=""
                        className="h-14 w-10 shrink-0 rounded object-cover"
                        src={result.coverUrl}
                      />
                    ) : (
                      <div className="flex h-14 w-10 shrink-0 items-center justify-center rounded bg-surface-2">
                        <Gamepad2 aria-hidden="true" className="size-8 text-muted-foreground" />
                      </div>
                    )}
                    <span className="text-sm font-medium text-foreground">{result.name}</span>
                  </button>
                </li>
              ))}
              {(page > 0 || hasMore) && (
                <li className="flex items-center justify-between border-t border-border px-3 py-2">
                  <button
                    className="text-xs text-muted-foreground hover:text-foreground disabled:opacity-30"
                    disabled={page === 0}
                    type="button"
                    onClick={() => handlePageChange(-1)}
                  >
                    ← Précédent
                  </button>
                  <span className="text-xs text-muted-foreground">Page {page + 1}</span>
                  <button
                    className="text-xs text-muted-foreground hover:text-foreground disabled:opacity-30"
                    disabled={!hasMore}
                    type="button"
                    onClick={() => handlePageChange(1)}
                  >
                    Suivant →
                  </button>
                </li>
              )}
            </>
          )}
        </ul>
      ) : null}
    </div>
  );
}
