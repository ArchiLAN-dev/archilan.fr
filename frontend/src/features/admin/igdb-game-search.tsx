"use client";

import { Gamepad2 } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import { env } from "@/lib/env";

export type IgdbResult = {
  igdbId: number;
  name: string;
  slug: string;
  summary: string | null;
  coverUrl: string | null;
};

const PAGE_SIZE = 10;

type Status = "idle" | "loading" | "error" | "done";

export function IgdbGameSearch({ onSelect }: { onSelect: (result: IgdbResult) => void }) {
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<IgdbResult[]>([]);
  const [status, setStatus] = useState<Status>("idle");
  const [open, setOpen] = useState(false);
  const [page, setPage] = useState(0);
  const [hasMore, setHasMore] = useState(false);

  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const abortRef = useRef<AbortController | null>(null);
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

  async function fetchResults(q: string, pageIndex: number, signal: AbortSignal) {
    setStatus("loading");
    setOpen(true);
    try {
      const offset = pageIndex * PAGE_SIZE;
      const res = await fetch(
        `${env.apiBaseUrl}/admin/igdb/search?q=${encodeURIComponent(q)}&offset=${offset}`,
        { credentials: "include", signal },
      );
      if (!res.ok) {
        setStatus("error");
        return;
      }
      const payload: unknown = await res.json();
      if (!isIgdbPayload(payload)) {
        setStatus("error");
        return;
      }
      setResults(payload.data);
      setHasMore(payload.meta.hasMore);
      setStatus("done");
    } catch (err) {
      if (err instanceof DOMException && err.name === "AbortError") return;
      setStatus("error");
    }
  }

  function handleQueryChange(q: string) {
    setQuery(q);
    setPage(0);
    if (timerRef.current) clearTimeout(timerRef.current);
    abortRef.current?.abort();
    if (!q.trim()) {
      setStatus("idle");
      setResults([]);
      setHasMore(false);
      setOpen(false);
      return;
    }
    timerRef.current = setTimeout(() => {
      abortRef.current = new AbortController();
      void fetchResults(q, 0, abortRef.current.signal);
    }, 300);
  }

  function handlePageChange(delta: number) {
    const next = page + delta;
    setPage(next);
    abortRef.current?.abort();
    abortRef.current = new AbortController();
    void fetchResults(query, next, abortRef.current.signal);
  }

  function handleSelect(result: IgdbResult) {
    onSelect(result);
    setOpen(false);
    setQuery("");
    setResults([]);
    setHasMore(false);
    setPage(0);
    setStatus("idle");
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
          if (query.trim() && (results.length > 0 || status === "loading")) setOpen(true);
        }}
      />

      {showDropdown ? (
        <ul className="absolute left-0 right-0 top-full z-50 mt-1 max-h-72 overflow-y-auto rounded border border-border bg-surface shadow-lg">
          {status === "loading" ? (
            <li className="px-4 py-3 text-sm text-muted-foreground">Recherche en cours…</li>
          ) : status === "error" ? (
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

function isIgdbPayload(payload: unknown): payload is { data: IgdbResult[]; meta: { hasMore: boolean } } {
  if (!payload || typeof payload !== "object") return false;
  if (!("data" in payload) || !Array.isArray((payload as { data: unknown }).data)) return false;
  if (!("meta" in payload)) return false;
  const meta = (payload as { meta: unknown }).meta;
  return typeof meta === "object" && meta !== null && "hasMore" in meta && typeof (meta as { hasMore: unknown }).hasMore === "boolean";
}
