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

type Status = "idle" | "loading" | "error" | "done";

export function IgdbGameSearch({ onSelect }: { onSelect: (result: IgdbResult) => void }) {
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<IgdbResult[]>([]);
  const [status, setStatus] = useState<Status>("idle");
  const [open, setOpen] = useState(false);

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

  async function fetchResults(q: string, signal: AbortSignal) {
    setStatus("loading");
    setOpen(true);
    try {
      const res = await fetch(
        `${env.apiBaseUrl}/admin/igdb/search?q=${encodeURIComponent(q)}`,
        { credentials: "include", signal },
      );
      if (!res.ok) {
        setStatus("error");
        return;
      }
      const payload: unknown = await res.json();
      const data = isIgdbPayload(payload) ? payload.data : [];
      setResults(data);
      setStatus("done");
    } catch (err) {
      if (err instanceof DOMException && err.name === "AbortError") return;
      setStatus("error");
    }
  }

  function handleQueryChange(q: string) {
    setQuery(q);
    if (timerRef.current) clearTimeout(timerRef.current);
    abortRef.current?.abort();
    if (!q.trim()) {
      setStatus("idle");
      setResults([]);
      setOpen(false);
      return;
    }
    timerRef.current = setTimeout(() => {
      abortRef.current = new AbortController();
      void fetchResults(q, abortRef.current.signal);
    }, 300);
  }

  function handleSelect(result: IgdbResult) {
    onSelect(result);
    setOpen(false);
    setQuery("");
    setResults([]);
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
            results.map((result) => (
              <li key={result.igdbId}>
                <button
                  className="flex w-full items-center gap-3 px-3 py-2 text-left transition-colors hover:bg-surface-2"
                  type="button"
                  onClick={() => handleSelect(result)}
                >
                  {result.coverUrl ? (
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
            ))
          )}
        </ul>
      ) : null}
    </div>
  );
}

function isIgdbPayload(payload: unknown): payload is { data: IgdbResult[] } {
  if (!payload || typeof payload !== "object" || !("data" in payload)) return false;
  return Array.isArray((payload as { data: unknown }).data);
}
