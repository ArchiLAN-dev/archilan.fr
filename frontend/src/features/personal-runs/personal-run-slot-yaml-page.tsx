"use client";

import Link from "next/link";
import { use, useEffect, useState } from "react";
import { AlertCircle, ArrowLeft } from "lucide-react";

import { YamlOptionEditor } from "@/features/events/yaml-option-editor";
import { apiFetch } from "@/lib/apiFetch";
import type { OptionTypesMap } from "@/lib/archipelago-yaml";
import { env } from "@/lib/env";

// ─── Types ────────────────────────────────────────────────────────────────────

type SlotInfo = {
  slotId: string;
  slotOrder: number;
  gameId: string;
  gameName: string;
  playerYaml: string | null;
  apworldHash: string | null;
};

type GameInfo = {
  id: string;
  isApworldReady: boolean;
  defaultYaml: string | null;
  optionTypes: OptionTypesMap | null;
};

type PageData = {
  slot: SlotInfo;
  game: GameInfo;
};

type PageState =
  | { kind: "loading" }
  | { kind: "data"; data: PageData }
  | { kind: "not_found" }
  | { kind: "error"; message: string };

// ─── Main page ────────────────────────────────────────────────────────────────

export function PersonalRunSlotYamlPage({
  params,
}: {
  params: Promise<{ runId: string; slotId: string }>;
}) {
  const { runId, slotId } = use(params);
  const [pageState, setPageState] = useState<PageState>({ kind: "loading" });
  const [dirty, setDirty] = useState(false);

  useEffect(() => {
    let cancelled = false;

    async function run() {
      const res = await apiFetch(`${env.apiBaseUrl}/runs/${runId}/participants/me/game-selection`);

      if (cancelled) return;

      if (res.status === 401 || res.status === 403) {
        window.location.href = `/connexion?returnTo=/runs/${runId}/slots/${slotId}`;
        return;
      }

      if (res.status === 404) {
        setPageState({ kind: "not_found" });
        return;
      }

      if (!res.ok) {
        setPageState({ kind: "error", message: "Impossible de charger les informations du slot." });
        return;
      }

      const payload = (await res.json()) as {
        data: {
          slots: Array<{
            slotId: string;
            slotOrder: number;
            gameId: string;
            gameName: string;
            playerYaml: string | null;
            apworldHash: string | null;
          }>;
          availableGames: Array<{
            id: string;
            isApworldReady: boolean;
            defaultYaml: string | null;
            optionTypes: OptionTypesMap | null;
          }>;
        };
      };

      const slot = payload.data.slots.find((s) => s.slotId === slotId) ?? null;
      if (!slot) {
        setPageState({ kind: "not_found" });
        return;
      }

      const game = payload.data.availableGames.find((g) => g.id === slot.gameId) ?? null;
      if (!game) {
        setPageState({ kind: "not_found" });
        return;
      }

      setPageState({
        kind: "data",
        data: { slot, game },
      });
    }

    void run().catch(() => {
      if (!cancelled) setPageState({ kind: "error", message: "Impossible de contacter l'API." });
    });

    return () => { cancelled = true; };
  }, [runId, slotId]);

  if (pageState.kind === "loading") {
    return (
      <div aria-hidden className="mx-auto max-w-2xl grid gap-6">
        <div className="h-8 w-48 animate-pulse rounded bg-surface" />
        <div className="h-64 animate-pulse rounded-lg border border-border bg-surface" />
      </div>
    );
  }

  if (pageState.kind === "not_found") {
    return (
      <div className="mx-auto max-w-2xl grid gap-4 rounded-lg border border-border p-8 text-center">
        <AlertCircle aria-hidden className="mx-auto size-8 text-[color:var(--color-danger)]" />
        <p className="font-heading text-xl font-semibold text-foreground">Slot introuvable</p>
        <p className="text-sm text-muted-foreground">Ce slot n&apos;existe pas ou n&apos;est plus accessible.</p>
        <Link className="text-sm text-accent-text hover:text-accent-text-hover" href={`/runs/${runId}/jeux`}>
          Retour à la sélection de jeux
        </Link>
      </div>
    );
  }

  if (pageState.kind === "error") {
    return (
      <div className="mx-auto max-w-2xl grid gap-4 rounded-lg border border-border p-8 text-center">
        <AlertCircle aria-hidden className="mx-auto size-8 text-[color:var(--color-danger)]" />
        <p className="font-heading text-xl font-semibold text-foreground">Erreur</p>
        <p className="text-sm text-muted-foreground">{pageState.message}</p>
      </div>
    );
  }

  const { data } = pageState;

  if (!data.game.isApworldReady) {
    return (
      <div className="mx-auto max-w-2xl grid gap-6">
        <header className="grid gap-2">
          <Link
            className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground w-fit"
            href={`/runs/${runId}/jeux`}
          >
            <ArrowLeft aria-hidden className="size-3.5" />
            Retour à la sélection
          </Link>
          <h1 className="font-heading text-2xl font-bold text-foreground">
            {data.slot.gameName}
          </h1>
        </header>
        <div className="rounded-lg border border-border bg-surface p-6">
          <p className="text-sm text-muted-foreground">
            Ce jeu n&apos;a pas encore de fichier .apworld configuré. La configuration YAML n&apos;est pas encore disponible.
          </p>
        </div>
      </div>
    );
  }

  const saveUrl = `${env.apiBaseUrl}/runs/${runId}/participants/me/slots/${slotId}/yaml`;

  return (
    <div className="mx-auto max-w-2xl grid gap-6">
      <header className="grid gap-2">
        <Link
          className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground w-fit"
          href={`/runs/${runId}/jeux`}
        >
          <ArrowLeft aria-hidden className="size-3.5" />
          Retour à la sélection
        </Link>
        <h1 className="font-heading text-2xl font-bold text-foreground">
          {data.slot.gameName}
        </h1>
        {dirty && (
          <p className="text-xs text-[color:var(--color-accent-warm)]">Modifications non sauvegardées</p>
        )}
      </header>

      <YamlOptionEditor
        defaultYaml={data.game.defaultYaml}
        optionTypes={data.game.optionTypes}
        playerYaml={data.slot.playerYaml}
        registrationId={runId}
        registrationOpen
        saveUrl={saveUrl}
        slotId={slotId}
        onDirty={() => setDirty(true)}
        onSaved={() => setDirty(false)}
      />
    </div>
  );
}
