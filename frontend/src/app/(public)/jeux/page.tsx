import { ChevronLeft, ChevronRight, Gamepad2, Search } from "lucide-react";
import type { Metadata } from "next";
import Link from "next/link";

import { GameCard } from "@/features/games/game-card";
import { getPublicGames } from "@/features/games/public-games-api";

export const dynamic = "force-dynamic";

export const metadata: Metadata = {
  title: "Jeux",
  description: "Bibliothèque de jeux Archipelago supportés par ArchiLAN.",
};

export default async function GamesPage({
  searchParams,
}: {
  searchParams: Promise<{ q?: string; page?: string }>;
}) {
  const { q, page: pageParam } = await searchParams;
  const query = q?.trim() ?? "";
  const page = Math.max(1, parseInt(pageParam ?? "1", 10) || 1);

  const { games, total, totalPages } = await getPublicGames(query, page);

  function pageHref(p: number) {
    const params = new URLSearchParams();
    if (query) params.set("q", query);
    if (p > 1) params.set("page", String(p));
    const qs = params.size > 0 ? `?${params}` : "";
    return `/jeux${qs}`;
  }

  return (
    <div className="mx-auto w-full max-w-7xl grid gap-16">
      <section>
        <p className="mb-4 text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">
          Bibliothèque ArchiLAN
        </p>
        <h1 className="font-heading text-4xl font-bold leading-tight md:text-5xl">
          Les jeux de la communauté.
        </h1>
        <p className="mt-5 max-w-2xl text-lg leading-8 text-muted-foreground">
          Tous les jeux supportés dans nos événements Archipelago. Chacun a été intégré et testé par
          l&apos;équipe ArchiLAN.
        </p>
      </section>

      <section aria-labelledby="games-heading">
        <h2 className="sr-only" id="games-heading">
          Catalogue des jeux
        </h2>

        <form action="/jeux" method="GET" role="search">
          <div className="relative max-w-md">
            <Search
              aria-hidden="true"
              className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <input
              aria-label="Rechercher un jeu"
              className="min-h-11 w-full rounded border border-border bg-background py-2 pl-10 pr-4 text-sm outline-none transition-colors focus:border-accent"
              defaultValue={query}
              name="q"
              placeholder="Hollow Knight, Stardew Valley…"
              type="search"
            />
          </div>
        </form>

        {games.length > 0 ? (
          <>
            <div className="mt-8 grid gap-5 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
              {games.map((game) => (
                <GameCard game={game} key={game.id} />
              ))}
            </div>

            <div className="mt-8 flex flex-col items-center gap-4 sm:flex-row sm:justify-between">
              <p className="text-sm text-muted-foreground">
                {total} jeu{total !== 1 ? "x" : ""}
                {query ? ` pour « ${query} »` : ""}
                {totalPages > 1 ? ` - page ${page} sur ${totalPages}` : ""}
              </p>

              {totalPages > 1 && (
                <nav aria-label="Pagination" className="flex items-center gap-1">
                  <Link
                    aria-disabled={page <= 1}
                    aria-label="Page précédente"
                    className={`inline-flex size-9 items-center justify-center rounded border transition-colors ${page <= 1 ? "pointer-events-none border-border/50 text-muted-foreground/40" : "border-border text-foreground hover:border-accent"}`}
                    href={pageHref(page - 1)}
                  >
                    <ChevronLeft aria-hidden="true" className="size-4" />
                  </Link>

                  <PageNumbers current={page} total={totalPages} pageHref={pageHref} />

                  <Link
                    aria-disabled={page >= totalPages}
                    aria-label="Page suivante"
                    className={`inline-flex size-9 items-center justify-center rounded border transition-colors ${page >= totalPages ? "pointer-events-none border-border/50 text-muted-foreground/40" : "border-border text-foreground hover:border-accent"}`}
                    href={pageHref(page + 1)}
                  >
                    <ChevronRight aria-hidden="true" className="size-4" />
                  </Link>
                </nav>
              )}
            </div>
          </>
        ) : (
          <div className="mt-8 card-glow rounded-lg border border-border p-10 text-center">
            <Gamepad2 aria-hidden="true" className="mx-auto mb-4 size-10 text-accent-text" />
            {query ? (
              <>
                <p className="font-heading text-xl font-semibold text-foreground">
                  Aucun jeu trouvé pour « {query} »
                </p>
                <p className="mx-auto mt-3 max-w-md text-sm leading-6 text-muted-foreground">
                  Essaie un autre terme ou{" "}
                  <a className="underline hover:text-foreground" href="/jeux">
                    voir tous les jeux
                  </a>
                  .
                </p>
              </>
            ) : (
              <>
                <p className="font-heading text-xl font-semibold text-foreground">
                  Aucun jeu disponible pour l&apos;instant
                </p>
                <p className="mx-auto mt-3 max-w-md text-sm leading-6 text-muted-foreground">
                  La bibliothèque sera enrichie avant les prochains événements.
                </p>
              </>
            )}
          </div>
        )}
      </section>
    </div>
  );
}

function PageNumbers({
  current,
  total,
  pageHref,
}: {
  current: number;
  total: number;
  pageHref: (p: number) => string;
}) {
  const pages: (number | "…")[] = [];

  if (total <= 7) {
    for (let i = 1; i <= total; i++) pages.push(i);
  } else {
    pages.push(1);
    if (current > 3) pages.push("…");
    for (let i = Math.max(2, current - 1); i <= Math.min(total - 1, current + 1); i++) {
      pages.push(i);
    }
    if (current < total - 2) pages.push("…");
    pages.push(total);
  }

  return (
    <>
      {pages.map((p, i) =>
        p === "…" ? (
          <span className="inline-flex size-9 items-center justify-center text-sm text-muted-foreground" key={`ellipsis-${i}`}>
            …
          </span>
        ) : (
          <Link
            aria-current={p === current ? "page" : undefined}
            className={`inline-flex size-9 items-center justify-center rounded border text-sm transition-colors ${p === current ? "border-accent bg-accent font-semibold text-white" : "border-border text-foreground hover:border-accent"}`}
            href={pageHref(p)}
            key={p}
          >
            {p}
          </Link>
        ),
      )}
    </>
  );
}
