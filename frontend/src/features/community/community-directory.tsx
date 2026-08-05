"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Loader2, Search } from "lucide-react";

import { useAuth } from "@/features/auth/auth-context";
import { fetchDirectory, type DirectorySort } from "./community-directory-api";
import { MemberCard } from "./member-card";

const STALE_TIME = 20_000;

const SORTS: { value: DirectorySort; label: string }[] = [
  { value: "xp", label: "Par XP" },
  { value: "recent", label: "Activité récente" },
];

type Props = {
  /** Term handed over by the hub's search box (/joueurs?search=…). */
  initialSearch?: string;
};

/**
 * The members directory (story 30.38). One list, three controls that compose: a search, a sort and a
 * friends filter.
 *
 * It used to be three exclusive tabs plus a search that silently replaced the active one - two sorts and
 * a personal filter presented as if they were the same kind of choice. They are separated here, and the
 * API composes them, so "chercher parmi mes amis" means what it says.
 */
export function CommunityDirectory({ initialSearch = "" }: Props) {
  const { user } = useAuth();
  const [sort, setSort] = useState<DirectorySort>("xp");
  const [friendsOnly, setFriendsOnly] = useState(false);
  const [searchInput, setSearchInput] = useState(initialSearch);
  const [search, setSearch] = useState(initialSearch);
  const [page, setPage] = useState(1);
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => () => {
    if (debounceRef.current) clearTimeout(debounceRef.current);
  }, []);

  const { data, isLoading, isError } = useQuery({
    queryKey: ["community-directory", sort, search, friendsOnly, page],
    queryFn: () => fetchDirectory({ sort, search, friendsOnly, page }),
    placeholderData: keepPreviousData,
    staleTime: STALE_TIME,
  });

  function onSearchChange(value: string): void {
    setSearchInput(value);
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => {
      setSearch(value);
      setPage(1);
    }, 350);
  }

  const rows = data?.rows ?? [];
  const total = data?.total ?? 0;
  const perPage = data?.perPage ?? 24;
  const totalPages = perPage > 0 ? Math.max(1, Math.ceil(total / perPage)) : 1;
  const searching = search.trim() !== "";
  // A rank is only meaningful on the XP order; under "activité récente" the number would rank nothing.
  const showRank = sort === "xp";

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
          Tous les joueurs ArchiLAN. Chacun a son profil : niveau, succès et historique de runs.
        </p>
      </header>

      <div className="grid gap-3">
        <label className="relative block">
          <Search aria-hidden className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <input
            aria-label="Rechercher un membre"
            className="min-h-11 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none"
            onChange={(e) => onSearchChange(e.target.value)}
            placeholder="Rechercher un membre par pseudo…"
            type="search"
            value={searchInput}
          />
        </label>

        <div className="flex flex-wrap items-center gap-x-5 gap-y-3">
          <div className="flex items-center gap-2">
            <label className="text-sm text-muted-foreground" htmlFor="directory-sort">
              Trier :
            </label>
            <select
              className="min-h-9 rounded-lg border border-border bg-surface px-3 text-sm text-foreground focus:border-accent focus:outline-none"
              id="directory-sort"
              onChange={(e) => {
                setSort(e.target.value === "recent" ? "recent" : "xp");
                setPage(1);
              }}
              value={sort}
            >
              {SORTS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </div>

          {user !== null ? (
            <label className="inline-flex items-center gap-2 text-sm text-foreground">
              <input
                checked={friendsOnly}
                className="size-4 rounded border-border text-accent focus:ring-accent"
                onChange={(e) => {
                  setFriendsOnly(e.target.checked);
                  setPage(1);
                }}
                type="checkbox"
              />
              Mes amis uniquement
            </label>
          ) : null}

          <p className="text-xs text-muted-foreground">
            {isLoading ? (
              <span className="inline-flex items-center gap-1.5">
                <Loader2 aria-hidden className="size-3.5 animate-spin" /> Chargement…
              </span>
            ) : (
              `${total} ${total === 1 ? "membre" : "membres"}`
            )}
          </p>
        </div>
      </div>

      {isError || data === null ? (
        <p className="text-sm text-muted-foreground">Impossible de charger l&apos;annuaire.</p>
      ) : rows.length === 0 && !isLoading ? (
        <p className="rounded-lg border border-border bg-surface px-4 py-8 text-center text-sm text-muted-foreground">
          {searching && friendsOnly
            ? `Aucun de tes amis ne correspond à « ${search.trim()} ».`
            : searching
              ? `Aucun membre ne correspond à « ${search.trim()} ».`
              : friendsOnly
                ? "Tu n'as pas encore d'amis. Ajoute-les depuis leur profil."
                : "Aucun membre à afficher."}
        </p>
      ) : (
        <>
          <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3" role="list">
            {rows.map((row, i) => (
              <li key={row.slug}>
                <MemberCard rank={showRank ? (page - 1) * perPage + i + 1 : null} row={row} />
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
