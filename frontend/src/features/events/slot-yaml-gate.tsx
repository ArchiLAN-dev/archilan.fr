"use client";

import Link from "next/link";
import { use, useEffect, useState } from "react";
import { AlertCircle, ArrowLeft, Lock, XCircle } from "lucide-react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { YamlOptionEditor } from "@/features/events/yaml-option-editor";

// ─── Types ───────────────────────────────────────────────────────────────────

type SlotInfo = {
  slotId: string;
  slotOrder: number;
  gameId: string;
  gameName: string;
  playerYaml: string | null;
};

type GameInfo = {
  id: string;
  isApworldReady: boolean;
  defaultYaml: string | null;
};

type GateData = {
  eventTitle: string;
  registrationOpen: boolean;
  slot: SlotInfo;
  game: GameInfo;
  slotLabel: string;
};

type GateState =
  | { kind: "loading" }
  | { kind: "data"; data: GateData }
  | { kind: "not_found" }
  | { kind: "error"; message: string };

// ─── Main gate ───────────────────────────────────────────────────────────────

export function SlotYamlGate({
  params,
}: {
  params: Promise<{ eventSlug: string; registrationId: string; slotId: string }>;
}) {
  const { eventSlug, registrationId, slotId } = use(params);
  const [gateState, setGateState] = useState<GateState>({ kind: "loading" });

  useEffect(() => {
    let cancelled = false;

    async function run() {
      const profileRes = await apiFetch(`${env.apiBaseUrl}/account/profile`);

      if (cancelled) return;

      if (profileRes.status === 401 || profileRes.status === 403) {
        window.location.href = `/connexion?returnTo=/evenements/${eventSlug}/inscription/${registrationId}/slots/${slotId}`;
        return;
      }

      const res = await apiFetch(`${env.apiBaseUrl}/registrations/${registrationId}/game-selection`);

      if (cancelled) return;

      if (res.status === 404) {
        setGateState({ kind: "not_found" });
        return;
      }

      if (!res.ok) {
        setGateState({ kind: "error", message: "Impossible de charger les options de ce slot." });
        return;
      }

      const payload: unknown = await res.json();
      const data = parseGateData(payload, slotId);

      if (!data) {
        setGateState({ kind: "not_found" });
        return;
      }

      setGateState({ kind: "data", data });
    }

    void run().catch(() => {
      if (!cancelled) {
        setGateState({ kind: "error", message: "Impossible de contacter l'API." });
      }
    });

    return () => {
      cancelled = true;
    };
  }, [registrationId, eventSlug, slotId]);

  const backHref = `/evenements/${eventSlug}/inscription/${registrationId}/recap`;

  if (gateState.kind === "loading") {
    return (
      <div aria-hidden="true" className="mx-auto grid max-w-3xl gap-8">
        {/* back button */}
        <div className="h-9 w-44 animate-pulse rounded border border-border bg-surface-2" />
        {/* header */}
        <div className="grid gap-3">
          <div className="h-3 w-24 animate-pulse rounded bg-surface-2" />
          <div className="h-7 w-52 animate-pulse rounded bg-surface-2" />
          <div className="h-4 w-40 animate-pulse rounded bg-surface-2" />
        </div>
        {/* yaml editor area */}
        <div className="grid gap-2">
          <div className="h-4 w-20 animate-pulse rounded bg-surface-2" />
          <div className="h-96 w-full animate-pulse rounded-lg border border-border bg-surface-2" />
        </div>
        {/* save button */}
        <div className="h-11 w-40 animate-pulse rounded bg-surface-2" />
      </div>
    );
  }

  if (gateState.kind === "not_found") {
    return (
      <div className="grid gap-4 card-glow rounded-lg border border-border p-8 text-center">
        <XCircle aria-hidden="true" className="mx-auto size-8 text-danger" />
        <p className="font-heading text-xl font-semibold text-foreground">Slot introuvable</p>
        <p className="text-sm text-muted-foreground">
          Ce slot n&apos;existe pas ou n&apos;est plus accessible.
        </p>
        <Link className="text-sm text-accent-text hover:text-accent-text-hover" href={backHref}>
          Retour au récapitulatif
        </Link>
      </div>
    );
  }

  if (gateState.kind === "error") {
    return (
      <div className="grid gap-4 card-glow rounded-lg border border-border p-8 text-center">
        <AlertCircle aria-hidden="true" className="mx-auto size-8 text-danger" />
        <p className="font-heading text-xl font-semibold text-foreground">Erreur</p>
        <p className="text-sm text-muted-foreground">{gateState.message}</p>
        <Link className="text-sm text-accent-text hover:text-accent-text-hover" href={backHref}>
          Retour au récapitulatif
        </Link>
      </div>
    );
  }

  const { data } = gateState;

  if (!data.game.isApworldReady) {
    return (
      <div className="grid gap-4 card-glow rounded-lg border border-border p-8 text-center">
        <AlertCircle aria-hidden="true" className="mx-auto size-8 text-muted-foreground" />
        <p className="font-heading text-xl font-semibold text-foreground">Options non disponibles</p>
        <p className="text-sm text-muted-foreground">
          Ce jeu n&apos;a pas encore de fichier .apworld configuré.
        </p>
        <Link className="text-sm text-accent-text hover:text-accent-text-hover" href={backHref}>
          Retour au récapitulatif
        </Link>
      </div>
    );
  }

  return (
    <article className="mx-auto max-w-3xl grid gap-8">
      <header className="grid gap-5">
        <Link
          className="inline-flex w-fit items-center gap-1.5 rounded border border-border bg-background px-3 py-2 text-sm font-medium text-foreground transition-colors hover:border-accent"
          href={backHref}
        >
          <ArrowLeft aria-hidden="true" className="size-4" />
          Retour au récapitulatif
        </Link>

        <div className="grid gap-1.5">
          <p className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
            {data.eventTitle}
          </p>
          <h1 className="font-heading text-2xl font-bold leading-tight text-foreground">
            {data.slotLabel}
          </h1>
          <p className="text-sm text-muted-foreground">Configuration du randomizer</p>
        </div>

        {!data.registrationOpen ? (
          <div className="flex items-center gap-3 rounded-lg border border-border bg-surface px-4 py-3">
            <Lock aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
            <p className="text-sm text-muted-foreground">
              La période d&apos;inscription est terminée. Les options sont en lecture seule.
            </p>
          </div>
        ) : null}
      </header>

      <YamlOptionEditor
        defaultYaml={data.game.defaultYaml}
        playerYaml={data.slot.playerYaml}
        registrationId={registrationId}
        registrationOpen={data.registrationOpen}
        slotId={slotId}
        onDirty={() => undefined}
        onSaved={() => undefined}
      />

      <Link
        className="inline-flex min-h-11 w-full items-center justify-center gap-1.5 rounded border border-border bg-background px-3 text-sm font-medium text-foreground transition-colors hover:border-accent sm:w-fit sm:justify-start"
        href={backHref}
      >
        <ArrowLeft aria-hidden="true" className="size-4" />
        Retour au récapitulatif
      </Link>
    </article>
  );
}

// ─── Parser ───────────────────────────────────────────────────────────────────

function parseGateData(payload: unknown, targetSlotId: string): GateData | null {
  if (!payload || typeof payload !== "object") return null;
  const data = (payload as { data?: unknown }).data;
  if (!data || typeof data !== "object") return null;
  const d = data as Record<string, unknown>;

  if (
    typeof d.eventTitle !== "string" ||
    !Array.isArray(d.slots) ||
    !Array.isArray(d.availableGames)
  ) {
    return null;
  }

  const rawSlot = (d.slots as unknown[]).find((s) => {
    if (!s || typeof s !== "object") return false;
    return (s as Record<string, unknown>).slotId === targetSlotId;
  });

  if (!rawSlot || typeof rawSlot !== "object") return null;
  const s = rawSlot as Record<string, unknown>;

  if (
    typeof s.slotId !== "string" ||
    typeof s.slotOrder !== "number" ||
    typeof s.gameId !== "string" ||
    typeof s.gameName !== "string"
  ) {
    return null;
  }

  const slot: SlotInfo = {
    slotId: s.slotId,
    slotOrder: s.slotOrder,
    gameId: s.gameId,
    gameName: s.gameName,
    playerYaml: typeof s.playerYaml === "string" ? s.playerYaml : null,
  };

  const rawGame = (d.availableGames as unknown[]).find((g) => {
    if (!g || typeof g !== "object") return false;
    return (g as Record<string, unknown>).id === slot.gameId;
  });

  if (!rawGame || typeof rawGame !== "object") return null;
  const g = rawGame as Record<string, unknown>;
  if (typeof g.id !== "string") return null;

  const game: GameInfo = {
    id: g.id,
    isApworldReady: g.isApworldReady === true,
    defaultYaml: typeof g.defaultYaml === "string" ? g.defaultYaml : null,
  };

  // Compute slot label - need sibling count for same game
  const sameGameSlots = (d.slots as unknown[]).filter((s2) => {
    if (!s2 || typeof s2 !== "object") return false;
    return (s2 as Record<string, unknown>).gameId === slot.gameId;
  });
  const isMulti = sameGameSlots.length > 1;
  const slotLabel = isMulti ? `${slot.gameName} (monde ${slot.slotOrder})` : slot.gameName;

  return {
    eventTitle: d.eventTitle,
    registrationOpen: typeof d.registrationOpen === "boolean" ? d.registrationOpen : true,
    slot,
    game,
    slotLabel,
  };
}
