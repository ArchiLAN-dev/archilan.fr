"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Loader2, Search, Trophy, Users, Clock } from "lucide-react";

import { useAuth } from "@/features/auth/auth-context";
import { fetchDirectory, type DirectoryMode } from "./community-directory-api";
import { MemberCard } from "./member-card";

const STALE_TIME = 20_000;

const TABS: { mode: DirectoryMode; label: string; icon: typeof Trophy }[] = [
  { mode: "top", label: "Top joueurs", icon: Trophy },
  { mode: "recent", label: "Récemment actifs", icon: Clock },
  { mode: "friends", label: "Mes amis", icon: Users },
];

type Props = {
  /** Term handed over by the hub's search box (/joueurs?search=…). */
  initialSearch?: string;
};

export function CommunityDirectory({ initialSearch = "" }: Props) {
  const { user } = useAuth();
  const [mode, setMode] = useState<DirectoryMode>("top");
  const [searchInput, setSearchInput] = useState(initialSearch);
  const [search, setSearch] = useState(initialSearch);
  const [page, setPage] = useState(1);
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => () => {
    if (debounceRef.current) clearTimeout(debounceRef.current);
  }, []);

  const searching = search.trim() !== "";

  const { data, isLoading, isError } = useQuery({
    queryKey: ["community-directory", mode, search, page],
    queryFn: () => fetchDirectory({ mode, search, page }),
    staleTime: STALE_TIME,
    enabled: !(mode === "friends" && user === null && !searching),
  });

  function onSearchChange(value: string): void {
    setSearchInput(value);
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => {
      setSearch(value);
      setPage(1);
    }, 350);
  }

  function selectTab(next: DirectoryMode): void {
    setMode(next);
    setSearch("");
    setSearchInput("");
    setPage(1);
  }

  const rows = data?.rows ?? [];
  const total = data?.total ?? 0;
  const perPage = data?.perPage ?? 24;
  const totalPages = perPage > 0 ? Math.max(1, Math.ceil(total / perPage)) : 1;
  const needsLogin = mode === "friends" && user === null && !searching;

  return (
    <section className="grid gap-6">
      <header className="grid gap-2">
        <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-text">
          <Link className="hover:underline" href="/communaute">
            Communauté
          </Link>
        </p>
        <h1 className="font-heading text-3xl font-bold text-foreground">Membres</h1>
        <p className="text-sm text-muted-foreground">
          Tous les joueurs ArchiLAN : classement par XP, activité récente et tes amis.
        </p>
      </header>

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-wrap gap-2" role="tablist">
          {TABS.map(({ mode: m, label, icon: Icon }) => (
            <button
              aria-selected={mode === m && !searching}
              className={`inline-flex min-h-9 items-center gap-1.5 rounded-lg border px-3 text-sm font-semibold transition-colors ${
                mode === m && !searching
                  ? "border-accent bg-accent/10 text-accent-text"
                  : "border-border text-muted-foreground hover:border-accent hover:text-foreground"
              }`}
              key={m}
              onClick={() => selectTab(m)}
              role="tab"
              type="button"
            >
              <Icon aria-hidden className="size-4" /> {label}
            </button>
          ))}
        </div>

        <label className="relative block sm:w-64">
          <Search aria-hidden className="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <input
            aria-label="Rechercher un joueur"
            className="min-h-9 w-full rounded-lg border border-border bg-surface pl-8 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none"
            onChange={(e) => onSearchChange(e.target.value)}
            placeholder="Rechercher…"
            type="search"
            value={searchInput}
          />
        </label>
      </div>

      {searching ? (
        <p className="text-xs text-muted-foreground">Résultats pour « {search.trim()} »</p>
      ) : null}

      {needsLogin ? (
        <p className="rounded-lg border border-border bg-surface px-4 py-8 text-center text-sm text-muted-foreground">
          <Link className="font-semibold text-accent-text hover:underline" href="/connexion">
            Connecte-toi
          </Link>{" "}
          pour voir tes amis.
        </p>
      ) : isLoading ? (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement…
        </p>
      ) : isError || data === null ? (
        <p className="text-sm text-muted-foreground">Impossible de charger l&apos;annuaire.</p>
      ) : rows.length === 0 ? (
        <p className="rounded-lg border border-border bg-surface px-4 py-8 text-center text-sm text-muted-foreground">
          Aucun joueur à afficher.
        </p>
      ) : (
        <>
          <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3" role="list">
            {rows.map((row, i) => (
              <li key={row.slug}>
                <MemberCard rank={mode === "top" && !searching ? (page - 1) * perPage + i + 1 : null} row={row} />
              </li>
            ))}
          </ul>

          {totalPages > 1 ? (
            <nav aria-label="Pagination" className="flex items-center justify-center gap-3">
              <button
                className="inline-flex min-h-9 items-center rounded-lg border border-border px-3 text-sm font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground disabled:opacity-40"
                disabled={page <= 1}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                type="button"
              >
                Précédent
              </button>
              <span className="text-xs text-muted-foreground">
                Page {page} / {totalPages}
              </span>
              <button
                className="inline-flex min-h-9 items-center rounded-lg border border-border px-3 text-sm font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground disabled:opacity-40"
                disabled={page >= totalPages}
                onClick={() => setPage((p) => p + 1)}
                type="button"
              >
                Suivant
              </button>
            </nav>
          ) : null}
        </>
      )}
    </section>
  );
}
