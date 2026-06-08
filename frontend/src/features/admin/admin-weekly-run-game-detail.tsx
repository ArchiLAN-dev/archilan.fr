"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, Gamepad2, Plus, RefreshCw } from "lucide-react";
import Link from "next/link";
import { notFound } from "next/navigation";
import { useState } from "react";

import { TemplateCard } from "./admin-weekly-run-cards";
import {
  ADMIN_WEEKLY_GAME_DETAIL_QUERY_KEY,
  ADMIN_WEEKLY_GAMES_QUERY_KEY,
  deactivateAdminWeeklyTemplate,
  fetchAdminWeeklyRunGames,
  fetchAdminWeeklyTemplates,
  triggerAdminWeeklyRunsGeneration,
} from "./admin-weekly-runs-api";
import type { AdminWeeklyRunGame, AdminWeeklyTemplateListItem } from "./admin-weekly-runs-api";

type GameDetailData = {
  game: AdminWeeklyRunGame | null;
  templates: AdminWeeklyTemplateListItem[];
};

async function fetchGameDetailData(gameId: string): Promise<GameDetailData | null> {
  const [games, templatesPayload] = await Promise.all([
    fetchAdminWeeklyRunGames(),
    fetchAdminWeeklyTemplates(),
  ]);
  if (!games || !templatesPayload) return null;
  return {
    game: games.find((g) => g.gameId === gameId) ?? null,
    templates: templatesPayload.data.filter((t) => t.gameId === gameId),
  };
}

export function AdminWeeklyRunGameDetail({ gameId }: { gameId: string }) {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({
    queryKey: [...ADMIN_WEEKLY_GAME_DETAIL_QUERY_KEY, gameId],
    queryFn: () => fetchGameDetailData(gameId),
    staleTime: 30_000,
    refetchInterval: 30_000,
  });
  const [deactivating, setDeactivating] = useState<string | null>(null);
  const [generating, setGenerating] = useState(false);

  async function invalidate() {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: [...ADMIN_WEEKLY_GAME_DETAIL_QUERY_KEY, gameId] }),
      queryClient.invalidateQueries({ queryKey: ADMIN_WEEKLY_GAMES_QUERY_KEY }),
    ]);
  }

  async function handleGenerate() {
    setGenerating(true);
    await triggerAdminWeeklyRunsGeneration();
    setGenerating(false);
    await invalidate();
  }

  async function handleDeactivate(id: string) {
    setDeactivating(id);
    const ok = await deactivateAdminWeeklyTemplate(id);
    setDeactivating(null);
    if (ok) {
      await invalidate();
    }
  }

  if (isLoading) {
    return (
      <div className="flex flex-col gap-8 p-6 md:p-8">
        <div className="h-6 w-48 animate-pulse rounded bg-surface-2" />
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <div className="h-40 animate-pulse rounded-xl bg-surface-2" />
          <div className="h-40 animate-pulse rounded-xl bg-surface-2" />
        </div>
      </div>
    );
  }

  if (!data) {
    return (
      <div className="flex items-center justify-center p-12">
        <div className="rounded-xl border border-danger/30 bg-danger/5 px-6 py-5 text-center">
          <p className="font-medium text-danger">Impossible de charger ce jeu</p>
          <p className="mt-1 text-sm text-muted-foreground">Recharge la page pour réessayer.</p>
        </div>
      </div>
    );
  }

  if (!data.game) {
    notFound();
  }

  const { game, templates } = data;

  return (
    <div className="flex flex-col gap-10 p-6 md:p-8">
      {/* Back link */}
      <Link
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
        href="/admin/weekly-runs"
      >
        <ArrowLeft aria-hidden="true" className="size-4" />
        Tous les jeux
      </Link>

      {/* Header */}
      <header className="flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-4">
          <div className="h-20 w-15 shrink-0 overflow-hidden rounded-lg bg-surface-2">
            {game.coverImageUrl ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                alt={game.coverImageAlt}
                className="h-full w-full object-cover"
                src={game.coverImageUrl}
              />
            ) : (
              <div className="flex h-full w-full items-center justify-center">
                <Gamepad2 aria-hidden="true" className="size-7 text-muted-foreground" />
              </div>
            )}
          </div>
          <div>
            <h2 className="font-heading text-xl font-bold text-foreground">{game.gameName}</h2>
            <p className="mt-0.5 text-sm text-muted-foreground">
              {game.runCount} run{game.runCount !== 1 ? "s" : ""} · {game.templateCount} template
              {game.templateCount !== 1 ? "s" : ""}
            </p>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <button
            className="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2.5 text-sm font-medium text-foreground transition-colors hover:bg-surface-2 disabled:opacity-50"
            disabled={generating}
            onClick={() => void handleGenerate()}
            type="button"
          >
            <RefreshCw aria-hidden="true" className={`size-4 ${generating ? "animate-spin" : ""}`} />
            {generating ? "Génération…" : "Générer maintenant"}
          </button>
          <Link
            className="inline-flex items-center gap-2 rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
            href={`/admin/weekly-runs/nouveau?gameId=${game.gameId}`}
          >
            <Plus aria-hidden="true" className="size-4" />
            Nouveau template
          </Link>
        </div>
      </header>

      {/* Templates section — click a template to see its run history */}
      <section>
        <h3 className="mb-4 font-heading text-lg font-bold text-foreground">Templates</h3>
        {templates.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            Aucun template pour ce jeu. Créez-en un pour démarrer des runs hebdomadaires.
          </p>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {templates.map((tpl) => (
              <TemplateCard
                deactivating={deactivating === tpl.id}
                key={tpl.id}
                onDeactivate={() => void handleDeactivate(tpl.id)}
                runsHref={`/admin/weekly-runs/template/${tpl.id}`}
                tpl={tpl}
              />
            ))}
          </div>
        )}
      </section>
    </div>
  );
}
