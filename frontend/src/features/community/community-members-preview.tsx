"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ArrowRight, Search } from "lucide-react";

import { useAuth } from "@/features/auth/auth-context";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchDirectory, type DirectoryRow } from "./community-directory-api";
import { MemberCard } from "./member-card";

const PREVIEW_SIZE = 8;

type Props = {
  /** Top members, fetched server-side so the section renders without JS. */
  initialRows: DirectoryRow[];
};

/**
 * The hub's members block (story 30.38): a preview, not the directory. Searching hands off to /joueurs,
 * which owns the full list, its modes and its pagination.
 *
 * The one connected touch on this page: a signed-in member sees their friends first. It is additive -
 * the anonymous rendering above is what the page is designed around (AC 10).
 */
export function CommunityMembersPreview({ initialRows }: Props) {
  const { user } = useAuth();
  const router = useRouter();
  const [search, setSearch] = useState("");

  const { data: friends } = useQuery({
    queryKey: ["community-directory", "friends", "", 1],
    queryFn: () => fetchDirectory({ mode: "friends", search: "", page: 1 }),
    staleTime: DEFAULT_STALE_TIME,
    enabled: user !== null,
  });

  const friendRows = friends?.rows ?? [];
  const friendSlugs = new Set(friendRows.map((row) => row.slug));
  const rows = [...friendRows, ...initialRows.filter((row) => !friendSlugs.has(row.slug))].slice(0, PREVIEW_SIZE);

  function submitSearch(event: React.FormEvent<HTMLFormElement>): void {
    event.preventDefault();
    const term = search.trim();
    router.push(term === "" ? "/joueurs" : `/joueurs?search=${encodeURIComponent(term)}`);
  }

  return (
    <div className="grid gap-5">
      <form className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" onSubmit={submitSearch}>
        <label className="relative block sm:w-72">
          <Search aria-hidden className="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <input
            aria-label="Rechercher un joueur"
            className="min-h-10 w-full rounded-lg border border-border bg-surface pl-8 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none"
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Rechercher un joueur…"
            type="search"
            value={search}
          />
        </label>
        <Link
          className="inline-flex min-h-10 shrink-0 items-center gap-1.5 rounded-lg border border-border bg-surface px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
          href="/joueurs"
        >
          Voir tous les membres
          <ArrowRight aria-hidden className="size-4" />
        </Link>
      </form>

      {rows.length === 0 ? (
        <p className="rounded-lg border border-border bg-surface px-4 py-8 text-center text-sm text-muted-foreground">
          Aucun membre à afficher pour le moment.
        </p>
      ) : (
        <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" role="list">
          {rows.map((row) => (
            <li key={row.slug}>
              <MemberCard row={row} />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
