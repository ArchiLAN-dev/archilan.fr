"use client";

import { useQuery } from "@tanstack/react-query";
import { Gamepad2, LayoutTemplate, Plus, Repeat } from "lucide-react";
import Link from "next/link";

import { ADMIN_WEEKLY_GAMES_QUERY_KEY, fetchAdminWeeklyRunGames } from "./admin-weekly-runs-api";
import type { AdminWeeklyRunGame } from "./admin-weekly-runs-api";

// ── Skeleton ──────────────────────────────────────────────────────────────────

function SkeletonCard() {
  return (
    <div className="animate-pulse overflow-hidden rounded-xl border border-border bg-surface">
      <div className="aspect-[3/4] w-full bg-surface-2" />
      <div className="flex flex-col gap-2 p-4">
        <div className="h-4 w-3/4 rounded bg-surface-2" />
        <div className="h-3 w-1/3 rounded bg-surface-2" />
      </div>
    </div>
  );
}

// ── Empty state ───────────────────────────────────────────────────────────────

function EmptyState() {
  return (
    <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-border bg-surface/50 px-6 py-16 text-center">
      <div className="mb-4 flex size-14 items-center justify-center rounded-full bg-surface-2">
        <LayoutTemplate aria-hidden="true" className="size-7 text-muted-foreground" />
      </div>
      <h3 className="font-heading text-base font-semibold text-foreground">
        Aucun jeu configuré
      </h3>
      <p className="mt-1.5 max-w-xs text-sm text-muted-foreground">
        Créez un premier template pour qu&apos;un jeu apparaisse ici avec ses runs hebdomadaires.
      </p>
      <Link
        className="mt-6 inline-flex items-center gap-2 rounded-lg bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
        href="/admin/weekly-runs/nouveau"
      >
        <Plus aria-hidden="true" className="size-4" />
        Créer le premier template
      </Link>
    </div>
  );
}

// ── Game card ─────────────────────────────────────────────────────────────────

function GameCard({ game }: { game: AdminWeeklyRunGame }) {
  return (
    <Link
      className="group relative overflow-hidden rounded-xl border border-border bg-surface transition-shadow hover:shadow-md focus:outline-none focus:ring-2 focus:ring-accent"
      href={`/admin/weekly-runs/jeu/${game.gameId}`}
    >
      {/* Run count badge */}
      <span
        className="absolute right-2 top-2 z-10 inline-flex items-center gap-1 rounded-full bg-black/70 px-2.5 py-1 text-xs font-semibold text-white backdrop-blur-sm"
        title={`${game.runCount} run${game.runCount !== 1 ? "s" : ""} au total`}
      >
        <Repeat aria-hidden="true" className="size-3.5" />
        {game.runCount}
      </span>

      <div className="aspect-[3/4] w-full overflow-hidden bg-surface-2">
        {game.coverImageUrl ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            alt={game.coverImageAlt}
            className="h-full w-full object-cover transition-transform duration-200 group-hover:scale-105"
            src={game.coverImageUrl}
          />
        ) : (
          <div className="flex h-full w-full items-center justify-center">
            <Gamepad2 aria-hidden="true" className="size-10 text-muted-foreground" />
          </div>
        )}
      </div>

      <div className="flex flex-col gap-0.5 p-4">
        <p className="truncate font-semibold text-foreground">{game.gameName}</p>
        <p className="text-xs text-muted-foreground">
          {game.templateCount} template{game.templateCount !== 1 ? "s" : ""}
        </p>
      </div>
    </Link>
  );
}

// ── Grid ──────────────────────────────────────────────────────────────────────

export function AdminWeeklyRunGameGrid() {
  const { data, isLoading } = useQuery({
    queryKey: ADMIN_WEEKLY_GAMES_QUERY_KEY,
    queryFn: fetchAdminWeeklyRunGames,
    staleTime: 30_000,
  });

  return (
    <div className="flex flex-col gap-8 p-6 md:p-8">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 className="font-heading text-lg font-bold text-foreground">
            Runs hebdomadaires par jeu
          </h2>
          <p className="mt-1 text-sm text-muted-foreground">
            Sélectionnez un jeu pour suivre ses runs de la semaine et gérer ses templates.
          </p>
        </div>
        <Link
          className="inline-flex items-center gap-2 rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
          href="/admin/weekly-runs/nouveau"
        >
          <Plus aria-hidden="true" className="size-4" />
          Nouveau template
        </Link>
      </div>

      {isLoading ? (
        <div className="grid gap-4 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          <SkeletonCard />
          <SkeletonCard />
          <SkeletonCard />
          <SkeletonCard />
          <SkeletonCard />
        </div>
      ) : !data ? (
        <div className="flex items-center justify-center p-12">
          <div className="rounded-xl border border-danger/30 bg-danger/5 px-6 py-5 text-center">
            <p className="font-medium text-danger">Impossible de charger les jeux</p>
            <p className="mt-1 text-sm text-muted-foreground">Recharge la page pour réessayer.</p>
          </div>
        </div>
      ) : data.length === 0 ? (
        <EmptyState />
      ) : (
        <div className="grid gap-4 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          {data.map((game) => (
            <GameCard game={game} key={game.gameId} />
          ))}
        </div>
      )}
    </div>
  );
}
