"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Loader2, Search, Trophy, Users, Clock } from "lucide-react";

import { useAuth } from "@/features/auth/auth-context";
import {
  fetchDirectory,
  type DirectoryMode,
  type DirectoryRow,
} from "./community-directory-api";

const STALE_TIME = 20_000;

const TABS: { mode: DirectoryMode; label: string; icon: typeof Trophy }[] = [
  { mode: "top", label: "Top joueurs", icon: Trophy },
  { mode: "recent", label: "Récemment actifs", icon: Clock },
  { mode: "friends", label: "Mes amis", icon: Users },
];

export function CommunityDirectory() {
  const { user } = useAuth();
  const [mode, setMode] = useState<DirectoryMode>("top");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
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
        <h1 className="font-heading text-3xl font-bold text-foreground">Communauté</h1>
        <p className="text-sm text-muted-foreground">
          Parcoure les joueurs ArchiLAN, le classement et tes amis.
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
                <PlayerCard rank={mode === "top" && !searching ? (page - 1) * perPage + i + 1 : null} row={row} />
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

function PlayerCard({ row, rank }: { row: DirectoryRow; rank: number | null }) {
  const [imgFailed, setImgFailed] = useState(false);
  const name = row.displayName ?? row.slug;
  const showImg = row.avatarUrl !== null && !imgFailed;

  return (
    <Link
      className="flex items-center gap-3 rounded-lg border border-border bg-surface p-3 transition-colors hover:border-accent"
      href={`/joueurs/${row.slug}`}
    >
      {rank !== null ? (
        <span className="w-6 shrink-0 text-center font-heading text-sm font-bold text-muted-foreground">{rank}</span>
      ) : null}
      <span className="relative inline-flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-accent/15 text-sm font-bold text-accent-text">
        {showImg ? (
          // eslint-disable-next-line @next/next/no-img-element -- avatar URLs are external (Discord/Steam CDN), not statically known
          <img alt="" className="size-full object-cover" onError={() => setImgFailed(true)} src={row.avatarUrl ?? ""} />
        ) : (
          name.slice(0, 1).toUpperCase()
        )}
        {row.playing ? (
          <span
            aria-label="En jeu"
            className="absolute -bottom-0.5 -right-0.5 size-3 animate-pulse rounded-full border-2 border-surface bg-emerald-400"
            title="En jeu"
          />
        ) : null}
      </span>
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-semibold text-foreground">{name}</span>
        <span className="text-xs text-muted-foreground">Niv. {row.level}</span>
      </span>
    </Link>
  );
}
