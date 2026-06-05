"use client";

import { Gamepad2 } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import { searchAdminGameOptions } from "./admin-weekly-runs-api";
import type { AdminGameOption } from "./admin-weekly-runs-api";

type Props = {
  value: AdminGameOption | null;
  onSelect: (game: AdminGameOption) => void;
  id?: string;
};

type Status = "idle" | "loading" | "error" | "done";

const DEBOUNCE_MS = 300;

export function AdminGamePicker({ value, onSelect, id }: Props) {
  const [query, setQuery] = useState(value?.name ?? "");
  const [results, setResults] = useState<AdminGameOption[]>([]);
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

  // Abort any in-flight request on unmount.
  useEffect(() => {
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current);
      abortRef.current?.abort();
    };
  }, []);

  function handleQueryChange(next: string) {
    setQuery(next);
    setOpen(true);
    if (timerRef.current) clearTimeout(timerRef.current);
    abortRef.current?.abort();

    if (!next.trim()) {
      setStatus("idle");
      setResults([]);
      return;
    }

    setStatus("loading");
    timerRef.current = setTimeout(() => {
      const controller = new AbortController();
      abortRef.current = controller;
      void (async () => {
        const games = await searchAdminGameOptions(next, controller.signal);
        if (controller.signal.aborted) return;
        setResults(games);
        setStatus("done");
      })();
    }, DEBOUNCE_MS);
  }

  function handleSelect(game: AdminGameOption) {
    onSelect(game);
    setQuery(game.name);
    setResults([]);
    setStatus("idle");
    setOpen(false);
  }

  const showDropdown = open && query.trim().length > 0;

  return (
    <div ref={containerRef} className="relative">
      <input
        autoComplete="off"
        className="w-full rounded border border-border bg-surface px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-accent"
        id={id}
        placeholder="Rechercher un jeu…"
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
            <li className="px-4 py-3 text-sm text-danger">Erreur lors de la recherche.</li>
          ) : results.length === 0 ? (
            <li className="px-4 py-3 text-sm text-muted-foreground">
              Aucun jeu prêt (APWorld configuré) pour «&nbsp;{query}&nbsp;»
            </li>
          ) : (
            results.map((game) => (
              <li key={game.id}>
                <button
                  className="flex w-full items-center gap-3 px-3 py-2 text-left transition-colors hover:bg-surface-2"
                  type="button"
                  onClick={() => handleSelect(game)}
                >
                  {game.coverImageUrl ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img
                      alt=""
                      className="h-12 w-9 shrink-0 rounded object-cover"
                      src={game.coverImageUrl}
                    />
                  ) : (
                    <div className="flex h-12 w-9 shrink-0 items-center justify-center rounded bg-surface-2">
                      <Gamepad2 aria-hidden="true" className="size-5 text-muted-foreground" />
                    </div>
                  )}
                  <span className="text-sm font-medium text-foreground">{game.name}</span>
                </button>
              </li>
            ))
          )}
        </ul>
      ) : null}
    </div>
  );
}
