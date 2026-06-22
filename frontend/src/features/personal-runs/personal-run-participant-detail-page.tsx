"use client";

import Link from "next/link";
import { use, useEffect, useState } from "react";
import { AlertCircle, ArrowLeft, ExternalLink, FileText, Gamepad2, ShieldCheck } from "lucide-react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import type { ParticipantGameSlot, ParticipantIdentity, ParticipantLevel, ParticipantStats } from "./types";
import { PersonalRunYamlViewerDialog } from "./personal-run-yaml-viewer-dialog";

const availabilityConfig: Record<string, { label: string; className: string }> = {
  available: { label: "Disponible", className: "border-success/50 bg-success/10 text-success" },
  experimental: { label: "Expérimental", className: "border-warning/50 bg-warning/10 text-warning" },
};

type PageState =
  | { kind: "loading" }
  | { kind: "not_found" }
  | { kind: "forbidden" }
  | { kind: "error"; message: string }
  | { kind: "ready"; participant: ParticipantIdentity; slots: ParticipantGameSlot[] };

function Avatar({ avatarUrl, name }: { avatarUrl: string | null; name: string }) {
  const [failed, setFailed] = useState(false);

  if (avatarUrl !== null && !failed) {
    return (
      // eslint-disable-next-line @next/next/no-img-element -- remote/presigned avatar URL, not a local asset
      <img
        alt=""
        aria-hidden="true"
        className="size-16 shrink-0 rounded-full bg-background object-cover"
        onError={() => setFailed(true)}
        src={avatarUrl}
      />
    );
  }

  return (
    <div className="flex size-16 shrink-0 items-center justify-center rounded-full bg-accent/20 text-lg font-semibold uppercase text-accent-text">
      {name.slice(0, 2)}
    </div>
  );
}

function LevelBar({ level }: { level: ParticipantLevel }) {
  const pct = level.xpForNextLevel > 0 ? Math.round((level.xpIntoLevel / level.xpForNextLevel) * 100) : 0;

  return (
    <div className="grid gap-1">
      <div className="flex justify-end text-[11px] text-muted-foreground">
        <span className="tabular-nums">
          {level.xpIntoLevel} / {level.xpForNextLevel} XP
        </span>
      </div>
      <div className="h-1.5 overflow-hidden rounded-full bg-background">
        <div className="h-full rounded-full bg-accent" style={{ width: `${pct}%` }} />
      </div>
    </div>
  );
}

function StatItem({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-lg border border-border bg-background px-3 py-2 text-center">
      <p className="text-lg font-bold tabular-nums text-foreground">{value.toLocaleString("fr-FR")}</p>
      <p className="text-[11px] text-muted-foreground">{label}</p>
    </div>
  );
}

function StatsGrid({ stats }: { stats: ParticipantStats }) {
  return (
    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
      <StatItem label="Parties" value={stats.runsParticipated} />
      <StatItem label="Objectifs" value={stats.goalCompletions} />
      <StatItem label="Checks" value={stats.totalChecksDone} />
      <StatItem label="Succès" value={stats.achievementsUnlocked} />
    </div>
  );
}

export function PersonalRunParticipantDetailPage({
  params,
}: {
  params: Promise<{ runId: string; participantId: string }>;
}) {
  const { runId, participantId } = use(params);
  const [pageState, setPageState] = useState<PageState>({ kind: "loading" });
  const [openSlot, setOpenSlot] = useState<ParticipantGameSlot | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function run() {
      const res = await apiFetch(
        `${env.apiBaseUrl}/runs/${runId}/participants/${participantId}/game-selection`,
      );
      if (cancelled) return;

      if (res.status === 401) {
        window.location.href = `/connexion?returnTo=/runs/${runId}/participants/${participantId}`;
        return;
      }
      if (res.status === 403) {
        setPageState({ kind: "forbidden" });
        return;
      }
      if (res.status === 404) {
        setPageState({ kind: "not_found" });
        return;
      }
      if (!res.ok) {
        setPageState({ kind: "error", message: "Impossible de charger la configuration du joueur." });
        return;
      }

      const payload = (await res.json()) as {
        data: { participant: ParticipantIdentity; slots: ParticipantGameSlot[] };
      };
      if (cancelled) return;
      setPageState({ kind: "ready", participant: payload.data.participant, slots: payload.data.slots });
    }

    void run().catch(() => {
      if (!cancelled) setPageState({ kind: "error", message: "Impossible de contacter l'API." });
    });

    return () => { cancelled = true; };
  }, [runId, participantId]);

  const backLink = (
    <Link
      className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      href={`/runs/${runId}?tab=participants`}
    >
      <ArrowLeft aria-hidden className="size-3.5" />
      Retour aux participants
    </Link>
  );

  if (pageState.kind === "loading") {
    return (
      <div aria-hidden="true" className="mx-auto grid max-w-3xl gap-6">
        <div className="h-4 w-40 animate-pulse rounded bg-surface" />
        <div className="h-40 animate-pulse rounded-lg border border-border bg-surface" />
        <div className="grid gap-3 sm:grid-cols-2">
          {[0, 1, 2, 3].map((i) => (
            <div className="h-32 animate-pulse rounded-lg border border-border bg-surface" key={i} />
          ))}
        </div>
      </div>
    );
  }

  if (pageState.kind === "not_found" || pageState.kind === "forbidden" || pageState.kind === "error") {
    const message =
      pageState.kind === "not_found"
        ? "Ce participant n'existe pas ou ne fait pas partie de cette partie."
        : pageState.kind === "forbidden"
          ? "Tu dois participer à cette partie pour voir la configuration des joueurs."
          : pageState.message;

    return (
      <div className="mx-auto grid max-w-3xl gap-6">
        {backLink}
        <div className="grid gap-4 rounded-lg border border-border p-8 text-center">
          <AlertCircle aria-hidden className="mx-auto size-8 text-[color:var(--color-danger)]" />
          <p className="font-heading text-xl font-semibold text-foreground">
            {pageState.kind === "not_found" ? "Participant introuvable" : "Accès refusé"}
          </p>
          <p className="text-sm text-muted-foreground">{message}</p>
        </div>
      </div>
    );
  }

  const { participant, slots } = pageState;
  const name = participant.displayName ?? "Joueur";

  return (
    <article className="mx-auto grid max-w-3xl gap-6">
      {backLink}

      {/* ── Player header ── */}
      <header className="grid gap-4 rounded-lg border border-border bg-surface p-5">
        <div className="flex items-center gap-4">
          <Avatar avatarUrl={participant.avatarUrl} name={name} />
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              {participant.slug !== null ? (
                <Link
                  className="truncate font-heading text-2xl font-bold text-foreground transition-colors hover:text-accent-text"
                  href={`/joueurs/${participant.slug}`}
                >
                  {name}
                </Link>
              ) : (
                <h1 className="truncate font-heading text-2xl font-bold text-foreground">{name}</h1>
              )}
              <span className="inline-flex items-center rounded-full border border-accent/40 bg-accent/10 px-2.5 py-0.5 text-xs font-semibold text-accent-text">
                Niv. {participant.level.level}
              </span>
              {participant.isAdmin && (
                <span className="inline-flex items-center gap-1 rounded-full border border-border bg-background px-2 py-0.5 text-xs font-semibold text-muted-foreground">
                  <ShieldCheck aria-hidden className="size-3" />
                  Admin
                </span>
              )}
            </div>
            <p className="mt-0.5 text-sm text-muted-foreground">
              {slots.length} jeu{slots.length > 1 ? "x" : ""} dans cette partie
            </p>
          </div>
        </div>

        <LevelBar level={participant.level} />
        <StatsGrid stats={participant.stats} />

        {participant.slug !== null && (
          <Link
            className="inline-flex w-fit items-center gap-1.5 text-xs font-semibold text-accent-text transition-colors hover:text-accent-text-hover"
            href={`/joueurs/${participant.slug}`}
          >
            Voir le profil complet
            <ExternalLink aria-hidden className="size-3" />
          </Link>
        )}
      </header>

      {/* ── Games ── */}
      {slots.length === 0 ? (
        <p className="rounded-lg border border-border bg-surface p-6 text-center text-sm text-muted-foreground">
          Ce joueur n&apos;a pas encore sélectionné de jeux.
        </p>
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2">
          {slots.map((slot) => {
            const availability = slot.availability !== null ? availabilityConfig[slot.availability] : undefined;
            const hasPublicPage =
              slot.gameSlug !== null &&
              (slot.availability === "available" || slot.availability === "experimental");

            return (
              <li
                className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 transition-colors hover:border-accent/40"
                key={slot.slotId}
              >
                <div className="flex items-start gap-3">
                  <div className="h-24 w-16 shrink-0 overflow-hidden rounded border border-border bg-background">
                    {slot.coverImageUrl ? (
                      // eslint-disable-next-line @next/next/no-img-element -- remote cover URL, not a local asset
                      <img
                        alt={slot.coverImageAlt}
                        className="h-full w-full object-cover object-top"
                        src={slot.coverImageUrl}
                      />
                    ) : (
                      <div className="flex h-full w-full items-center justify-center text-sm font-semibold text-muted-foreground">
                        {slot.gameName.slice(0, 2).toUpperCase()}
                      </div>
                    )}
                  </div>
                  <div className="min-w-0 flex-1">
                    {hasPublicPage ? (
                      <Link
                        className="inline-flex items-center gap-1.5 text-sm font-semibold leading-tight text-foreground transition-colors hover:text-accent-text hover:underline"
                        href={`/jeux/${slot.gameSlug}`}
                        rel="noopener noreferrer"
                        target="_blank"
                      >
                        {slot.gameName}
                        <ExternalLink aria-hidden className="size-3 shrink-0 text-muted-foreground" />
                      </Link>
                    ) : (
                      <p className="text-sm font-semibold leading-tight text-foreground">{slot.gameName}</p>
                    )}

                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                      {availability && (
                        <span
                          className={`rounded border px-1.5 py-0.5 text-[11px] font-semibold ${availability.className}`}
                        >
                          {availability.label}
                        </span>
                      )}
                      {slot.platforms.slice(0, 2).map((platform) => (
                        <span
                          className="rounded border border-border bg-background px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground"
                          key={platform}
                        >
                          {platform}
                        </span>
                      ))}
                    </div>

                    {slot.description !== null && slot.description !== "" && (
                      <p className="mt-1.5 line-clamp-2 text-xs text-muted-foreground">{slot.description}</p>
                    )}
                  </div>
                </div>

                {slot.playerYaml !== null ? (
                  <button
                    className="inline-flex min-h-9 w-full items-center justify-center gap-1.5 rounded border border-border px-3 text-xs font-semibold text-foreground transition-colors hover:border-accent hover:text-accent-text"
                    onClick={() => setOpenSlot(slot)}
                    type="button"
                  >
                    <Gamepad2 aria-hidden className="size-3.5" />
                    Voir la configuration
                  </button>
                ) : (
                  <p className="inline-flex items-center gap-1.5 text-xs text-muted-foreground/70">
                    <FileText aria-hidden className="size-3" />
                    Aucune configuration YAML.
                  </p>
                )}
              </li>
            );
          })}
        </ul>
      )}

      {openSlot !== null && openSlot.playerYaml !== null && (
        <PersonalRunYamlViewerDialog
          gameName={openSlot.gameName}
          onClose={() => setOpenSlot(null)}
          playerYaml={openSlot.playerYaml}
        />
      )}
    </article>
  );
}