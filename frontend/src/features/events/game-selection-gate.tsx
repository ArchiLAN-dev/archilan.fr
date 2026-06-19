"use client";

import Link from "next/link";
import { use, useCallback, useEffect, useRef, useState } from "react";
import { AlertCircle, ArrowLeft, CheckCircle, ChevronLeft, ChevronRight, Search, Trash2, X, XCircle } from "lucide-react";

import { RegistrationStepper } from "@/features/events/registration-stepper";
import { InstallNudge } from "@/features/games/install-nudge";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

// ─── Types ───────────────────────────────────────────────────────────────────

type AvailableGame = {
  id: string;
  name: string;
  slug: string;
  description: string;
  availability: string;
  isApworldReady: boolean;
  defaultYaml: string | null;
  coverImageUrl: string | null;
  coverImageAlt: string | null;
};

type Slot = {
  slotId: string;
  gameId: string;
  gameName: string;
};

type SelectionData = {
  registrationId: string;
  eventId: string;
  eventTitle: string;
  registrationOpen: boolean;
  gameSelectionEnabled: boolean;
  maxGamesPerRegistrant: number | null;
  slots: Slot[];
  availableGames: AvailableGame[];
};

type GateState =
  | { kind: "loading" }
  | { kind: "data"; data: SelectionData }
  | { kind: "not_found" }
  | { kind: "error"; message: string };

type SaveState =
  | { kind: "idle" }
  | { kind: "saving" }
  | { kind: "saved" }
  | { kind: "error"; message: string };

type CancelState =
  | { kind: "idle" }
  | { kind: "confirming" }
  | { kind: "cancelling" }
  | { kind: "cancelled" }
  | { kind: "error"; message: string };

const PAGE_SIZE = 20;

// ─── Main gate ───────────────────────────────────────────────────────────────

export function GameSelectionGate({
  params,
}: {
  params: Promise<{ eventSlug: string; registrationId: string }>;
}) {
  const { eventSlug, registrationId } = use(params);
  const [loadKey, setLoadKey] = useState(0);
  const [gateState, setGateState] = useState<GateState>({ kind: "loading" });
  const [workingGameIds, setWorkingGameIds] = useState<string[]>([]);
  const [gameSearch, setGameSearch] = useState("");
  const [currentPage, setCurrentPage] = useState(1);
  const [saveState, setSaveState] = useState<SaveState>({ kind: "idle" });
  const [cancelState, setCancelState] = useState<CancelState>({ kind: "idle" });
  const [coverPreview, setCoverPreview] = useState<{ url: string; x: number; y: number } | null>(null);
  const [justAdded, setJustAdded] = useState<Set<string>>(new Set());
  const [fadingOut, setFadingOut] = useState<Set<string>>(new Set());
  const addTimers = useRef<Map<string, [ReturnType<typeof setTimeout>, ReturnType<typeof setTimeout>]>>(new Map());

  useEffect(() => {
    const timers = addTimers.current;
    return () => { timers.forEach(([t1, t2]) => { clearTimeout(t1); clearTimeout(t2); }); };
  }, []);

  const handleAddGame = useCallback((gameId: string) => {
    setWorkingGameIds((prev) => [...prev, gameId]);
    setSaveState({ kind: "idle" });

    const existing = addTimers.current.get(gameId);
    if (existing) { clearTimeout(existing[0]); clearTimeout(existing[1]); }

    setFadingOut((prev) => { const next = new Set(prev); next.delete(gameId); return next; });
    setJustAdded((prev) => new Set(prev).add(gameId));

    const t1 = setTimeout(() => {
      setFadingOut((prev) => new Set(prev).add(gameId));
    }, 1100);

    const t2 = setTimeout(() => {
      setJustAdded((prev) => { const next = new Set(prev); next.delete(gameId); return next; });
      setFadingOut((prev) => { const next = new Set(prev); next.delete(gameId); return next; });
      addTimers.current.delete(gameId);
    }, 1400);

    addTimers.current.set(gameId, [t1, t2]);
  }, []);

  useEffect(() => {
    let cancelled = false;

    async function run() {
      const profileRes = await apiFetch(`${env.apiBaseUrl}/account/profile`);

      if (cancelled) return;

      if (profileRes.status === 401 || profileRes.status === 403) {
        window.location.href = `/connexion?returnTo=/evenements/${eventSlug}/inscription/${registrationId}/jeux`;
        return;
      }

      const res = await apiFetch(`${env.apiBaseUrl}/registrations/${registrationId}/game-selection`);

      if (cancelled) return;

      if (res.status === 404) {
        setGateState({ kind: "not_found" });
        return;
      }

      if (!res.ok) {
        setGateState({ kind: "error", message: "Impossible de charger la sélection de jeux." });
        return;
      }

      const payload: unknown = await res.json();
      const data = parseSelectionData(payload);

      if (!data) {
        setGateState({ kind: "error", message: "Réponse API invalide." });
        return;
      }

      setGateState({ kind: "data", data });
      setWorkingGameIds(data.slots.map((s) => s.gameId));
      setSaveState({ kind: "saved" });
    }

    void run().catch(() => {
      if (!cancelled) {
        setGateState({ kind: "error", message: "Impossible de contacter l'API." });
      }
    });

    return () => {
      cancelled = true;
    };
  }, [registrationId, eventSlug, loadKey]);

  async function handleSave() {
    if (gateState.kind !== "data") return;
    setSaveState({ kind: "saving" });
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/registrations/${registrationId}/game-selection`, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ gameIds: workingGameIds }),
      });
      if (!res.ok) {
        setSaveState({ kind: "error", message: "Impossible de sauvegarder la sélection." });
        return;
      }
      setSaveState({ kind: "saved" });
      setLoadKey((k) => k + 1);
    } catch {
      setSaveState({ kind: "error", message: "Impossible de contacter l'API." });
    }
  }

  async function handleCancelConfirmed() {
    setCancelState({ kind: "cancelling" });
    try {
      const res = await apiFetch(`${env.apiBaseUrl}/registrations/${registrationId}`, {
        method: "DELETE",
      });
      if (!res.ok) {
        const body = (await res.json()) as { error?: { message?: string } };
        setCancelState({
          kind: "error",
          message: body.error?.message ?? "Impossible d'annuler l'inscription.",
        });
        return;
      }
      setCancelState({ kind: "cancelled" });
    } catch {
      setCancelState({ kind: "error", message: "Impossible de contacter l'API." });
    }
  }

  if (gateState.kind === "loading") {
    return (
      <div aria-hidden="true" className="grid gap-8">
        {/* stepper */}
        <div className="flex items-center gap-2">
          {[0, 1, 2].map((i) => (
            <div className="flex items-center gap-2" key={i}>
              <div className="size-7 animate-pulse rounded-full bg-surface-2" />
              {i < 2 && <div className="h-px w-12 animate-pulse bg-surface-2" />}
            </div>
          ))}
        </div>
        {/* header */}
        <div className="grid gap-2">
          <div className="h-8 w-56 animate-pulse rounded bg-surface-2" />
          <div className="h-4 w-36 animate-pulse rounded bg-surface-2" />
        </div>
        {/* sélection card */}
        <div className="rounded-lg border border-border p-5">
          <div className="mb-4 h-5 w-28 animate-pulse rounded bg-surface-2" />
          <div className="h-4 w-64 animate-pulse rounded bg-surface-2" />
        </div>
        {/* game grid */}
        <div className="grid gap-3">
          <div className="h-10 w-full animate-pulse rounded bg-surface-2" />
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            {[0, 1, 2, 3, 4, 5].map((i) => (
              <div className="h-16 animate-pulse rounded-lg border border-border bg-surface-2" key={i} />
            ))}
          </div>
        </div>
      </div>
    );
  }

  if (gateState.kind === "not_found") {
    return (
      <div className="grid gap-4 card-glow rounded-lg border border-border p-8 text-center">
        <XCircle aria-hidden="true" className="mx-auto size-8 text-danger" />
        <p className="font-heading text-xl font-semibold text-foreground">Inscription introuvable</p>
        <p className="text-sm text-muted-foreground">
          Cette inscription n&apos;existe pas ou n&apos;est plus accessible.
        </p>
        <Link className="text-sm text-accent-text hover:text-accent-text-hover" href="/evenements">
          Voir tous les événements
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
      </div>
    );
  }

  if (cancelState.kind === "cancelled") {
    return (
      <div className="grid gap-4 card-glow rounded-lg border border-border p-8 text-center">
        <CheckCircle aria-hidden="true" className="mx-auto size-10 text-success" />
        <h1 className="font-heading text-2xl font-bold text-foreground">Inscription annulée</h1>
        <p className="text-sm text-muted-foreground">
          Ta place a été libérée. Tu peux revenir sur la page de l&apos;événement si tu changes d&apos;avis.
        </p>
        <Link
          className="inline-flex min-h-11 items-center justify-center justify-self-center rounded border border-accent px-5 text-sm font-semibold text-accent-text transition-colors hover:bg-accent/10"
          href={`/evenements/${eventSlug}`}
        >
          Retour à l&apos;événement
        </Link>
      </div>
    );
  }

  const { data } = gateState;
  const max = data.maxGamesPerRegistrant;
  const limitReached = max !== null && workingGameIds.length >= max;
  const gameMap = new Map(data.availableGames.map((g) => [g.id, g]));
  const canContinue =
    workingGameIds.length > 0 && saveState.kind === "saved" && data.registrationOpen;

  // Build labeled selection items (handle duplicate games)
  const occurrenceCounts: Record<string, number> = {};
  for (const id of workingGameIds) {
    occurrenceCounts[id] = (occurrenceCounts[id] ?? 0) + 1;
  }
  const occurrenceProgress: Record<string, number> = {};
  const selectionItems = workingGameIds.map((gameId, idx) => {
    occurrenceProgress[gameId] = (occurrenceProgress[gameId] ?? 0) + 1;
    const n = occurrenceProgress[gameId];
    const total = occurrenceCounts[gameId] ?? 1;
    const name = gameMap.get(gameId)?.name ?? gameId;
    return { gameId, idx, label: total > 1 ? `${name} (monde ${n})` : name };
  });

  // Distinct selected games (name + slug) for the post-selection install nudge (story 31.4).
  // Only games with a public detail page (available/experimental) - others would 404.
  const selectedGamesForNudge = Array.from(new Set(workingGameIds)).flatMap((id) => {
    const g = gameMap.get(id);
    return g && (g.availability === "available" || g.availability === "experimental")
      ? [{ name: g.name, slug: g.slug }]
      : [];
  });

  // Filtered + paginated games
  const q = gameSearch.trim().toLowerCase();
  const filteredGames =
    q === ""
      ? data.availableGames
      : data.availableGames.filter((g) => g.name.toLowerCase().includes(q));
  const totalPages = Math.max(1, Math.ceil(filteredGames.length / PAGE_SIZE));
  const safePage = Math.min(currentPage, totalPages);
  const pageGames = filteredGames.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

  function handleSearchChange(value: string) {
    setGameSearch(value);
    setCurrentPage(1);
  }

  return (
    <article className="grid gap-8">
      <RegistrationStepper currentStep={1} />

      <header className="grid gap-2">
        <h1 className="font-heading text-3xl font-bold leading-tight text-foreground">
          Sélection des jeux
        </h1>
        <p className="text-sm text-muted-foreground">{data.eventTitle}</p>
      </header>

      {/* ── Selection summary ── */}
      <section className="grid gap-4 card-glow rounded-lg border border-border p-5">
        <div className="flex items-center justify-between gap-2">
          <h2 className="font-heading text-lg font-semibold text-foreground">
            Ma sélection
            {workingGameIds.length > 0 && (
              <span className="ml-2 text-sm font-normal text-muted-foreground">
                ({workingGameIds.length}{max !== null ? ` / ${max}` : ""})
              </span>
            )}
          </h2>
          <div className="flex items-center gap-3">
            {saveState.kind === "saved" && (
              <span className="inline-flex items-center gap-1.5 rounded-full border border-success/20 bg-success/10 px-2.5 py-1 text-xs font-medium text-success">
                <CheckCircle aria-hidden="true" className="size-3" />
                Sauvegardé
              </span>
            )}
            {limitReached && (
              <span className="text-xs font-semibold text-accent-warm">Limite atteinte</span>
            )}
          </div>
        </div>

        {!data.registrationOpen ? (
          <p className="text-sm text-muted-foreground">
            La période d&apos;inscription est terminée. Ta sélection est visible en lecture seule.
          </p>
        ) : null}

        {selectionItems.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            Aucun jeu sélectionné. Parcourez le catalogue ci-dessous pour ajouter des jeux.
          </p>
        ) : (
          <ul className="grid gap-1.5" role="list">
            {selectionItems.map(({ idx, label }) => (
              <li
                key={idx}
                className="flex items-center justify-between gap-3 rounded border border-border bg-background px-3 py-2"
              >
                <span className="text-sm font-medium text-foreground">{label}</span>
                {data.registrationOpen && (
                  <button
                    aria-label={`Retirer ${label}`}
                    className="inline-flex size-7 cursor-pointer items-center justify-center rounded text-muted-foreground transition-colors hover:bg-danger/10 hover:text-danger"
                    type="button"
                    onClick={() => {
                      setWorkingGameIds((prev) => prev.filter((_, i) => i !== idx));
                      setSaveState({ kind: "idle" });
                    }}
                  >
                    <X aria-hidden="true" className="size-3.5" />
                  </button>
                )}
              </li>
            ))}
          </ul>
        )}

        {data.registrationOpen ? (
          <div className="grid gap-2">
            <div className="grid grid-cols-1 gap-2 sm:flex sm:flex-wrap sm:gap-3">
              <button
                className="inline-flex min-h-10 cursor-pointer items-center justify-center rounded bg-accent px-5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50"
                disabled={saveState.kind === "saving"}
                type="button"
                onClick={() => { void handleSave(); }}
              >
                {saveState.kind === "saving" ? "Sauvegarde…" : "Sauvegarder ma sélection"}
              </button>
              <button
                className="inline-flex min-h-10 cursor-pointer items-center justify-center rounded border border-accent px-5 text-sm font-semibold text-accent-text transition-colors hover:bg-accent/10 disabled:cursor-not-allowed disabled:opacity-40"
                disabled={!canContinue}
                title={
                  !canContinue
                    ? workingGameIds.length === 0
                      ? "Sélectionnez au moins un jeu"
                      : "Sauvegardez d'abord votre sélection"
                    : undefined
                }
                type="button"
                onClick={() => {
                  window.location.href = `/evenements/${eventSlug}/inscription/${registrationId}/recap`;
                }}
              >
                Continuer vers le récap →
              </button>
            </div>
            {saveState.kind === "error" && (
              <p className="text-xs text-danger">{saveState.message}</p>
            )}
          </div>
        ) : (
          <button
            className="inline-flex min-h-10 w-full items-center justify-center rounded border border-accent px-5 text-sm font-semibold text-accent-text transition-colors hover:bg-accent/10 sm:w-fit"
            type="button"
            onClick={() => {
              window.location.href = `/evenements/${eventSlug}/inscription/${registrationId}/recap`;
            }}
          >
            Voir le récapitulatif →
          </button>
        )}
      </section>

      <InstallNudge games={selectedGamesForNudge} />

      {/* ── Game catalog ── */}
      {!data.gameSelectionEnabled ? (
        <div className="card-glow rounded-lg border border-border p-6">
          <p className="text-sm text-muted-foreground">
            La sélection de jeux n&apos;est pas encore disponible pour cet événement.
          </p>
        </div>
      ) : (
        <section className="grid gap-4">
          <h2 className="font-heading text-xl font-semibold text-foreground">
            Catalogue des jeux
            <span className="ml-2 text-sm font-normal text-muted-foreground">
              ({data.availableGames.length})
            </span>
          </h2>

          <div className="relative">
            <Search
              aria-hidden="true"
              className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <input
              className="min-h-10 w-full rounded border border-border bg-background pl-9 pr-3 text-sm text-foreground outline-none focus:border-accent"
              placeholder={`Rechercher parmi ${data.availableGames.length} jeux…`}
              type="search"
              value={gameSearch}
              onChange={(e) => handleSearchChange(e.target.value)}
            />
          </div>

          {filteredGames.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              Aucun jeu ne correspond à la recherche.
            </p>
          ) : (
            <>
              <ul className="divide-y divide-border rounded-lg border border-border" role="list">
                {/* eslint-disable-next-line react-hooks/refs -- addTimers.current accessed only on click, not during render */}
                {pageGames.map((game) => (
                  <li
                    key={game.id}
                    className="flex items-center gap-3 bg-background px-3 py-3 first:rounded-t-lg last:rounded-b-lg transition-colors hover:bg-surface"
                  >
                    <div
                      className="h-16 w-12 shrink-0 cursor-default overflow-hidden rounded border border-border bg-surface"
                      onMouseEnter={game.coverImageUrl ? (e) => {
                        const rect = e.currentTarget.getBoundingClientRect();
                        setCoverPreview({ url: game.coverImageUrl!, x: rect.right + 10, y: rect.top });
                      } : undefined}
                      onMouseLeave={() => setCoverPreview(null)}
                    >
                      {game.coverImageUrl ? (
                        // eslint-disable-next-line @next/next/no-img-element
                        <img
                          alt={game.coverImageAlt ?? game.name}
                          className="h-full w-full object-cover object-top"
                          src={game.coverImageUrl}
                        />
                      ) : (
                        <div className="flex h-full w-full items-center justify-center text-xs font-semibold text-muted-foreground">
                          {game.name.slice(0, 2).toUpperCase()}
                        </div>
                      )}
                    </div>

                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-semibold leading-tight text-foreground">{game.name}</p>
                      {game.description && (
                        <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
                          {game.description}
                        </p>
                      )}
                    </div>

                    {data.registrationOpen && (() => {
                      const added = justAdded.has(game.id);
                      const fading = fadingOut.has(game.id);
                      return (
                        <button
                          className={[
                            "shrink-0 inline-flex min-h-9 cursor-pointer items-center justify-center gap-1.5 rounded border px-3 text-xs font-semibold transition-all duration-300 disabled:cursor-not-allowed disabled:opacity-40",
                            added
                              ? "border-success/30 bg-success/10 text-success"
                              : "border-border text-foreground hover:border-accent hover:text-accent-text",
                            fading ? "opacity-0" : "opacity-100",
                          ].join(" ")}
                          disabled={limitReached && !added}
                          title={limitReached && !added ? `Limite de ${max} jeux atteinte` : undefined}
                          type="button"
                          onClick={() => handleAddGame(game.id)}
                        >
                          {added ? (
                            <>
                              <CheckCircle aria-hidden="true" className="size-3" />
                              Ajouté
                            </>
                          ) : (
                            "+ Ajouter"
                          )}
                        </button>
                      );
                    })()}
                  </li>
                ))}
              </ul>

              {totalPages > 1 && (
                <div className="flex items-center justify-between gap-2">
                  <button
                    className="inline-flex min-h-9 cursor-pointer items-center justify-center gap-1 rounded border border-border px-3 text-xs font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-40"
                    disabled={safePage === 1}
                    type="button"
                    onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                  >
                    <ChevronLeft aria-hidden="true" className="size-3.5" />
                    Précédent
                  </button>
                  <span className="text-center text-xs text-muted-foreground">
                    {safePage} / {totalPages}
                    <span className="mx-1 text-border">·</span>
                    {filteredGames.length} jeux
                  </span>
                  <button
                    className="inline-flex min-h-9 cursor-pointer items-center justify-center gap-1 rounded border border-border px-3 text-xs font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-40"
                    disabled={safePage === totalPages}
                    type="button"
                    onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
                  >
                    Suivant
                    <ChevronRight aria-hidden="true" className="size-3.5" />
                  </button>
                </div>
              )}
            </>
          )}
        </section>
      )}

      {/* ── Footer ── */}
      <div className="flex flex-wrap items-center justify-between gap-4">
        <Link
          className="inline-flex min-h-10 items-center gap-2 rounded border border-border px-4 text-sm font-medium text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
          href={`/evenements/${eventSlug}/inscription`}
        >
          <ArrowLeft aria-hidden="true" className="size-4" />
          Retour à l&apos;inscription
        </Link>
        {cancelState.kind === "idle" || cancelState.kind === "error" ? (
          <button
            className="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded border border-danger/30 px-4 text-sm font-medium text-danger transition-colors hover:border-danger hover:bg-danger/10"
            type="button"
            onClick={() => setCancelState({ kind: "confirming" })}
          >
            <Trash2 aria-hidden="true" className="size-4" />
            Annuler mon inscription
          </button>
        ) : null}
      </div>

      {coverPreview && (
        <div
          aria-hidden="true"
          className="pointer-events-none fixed z-50 w-40 overflow-hidden rounded-xl border border-border shadow-2xl"
          style={{ left: coverPreview.x, top: coverPreview.y }}
        >
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img alt="" className="w-full h-auto block" src={coverPreview.url} />
        </div>
      )}

      {cancelState.kind === "confirming" ? (
        <div className="rounded-lg border border-danger/40 bg-surface/40 backdrop-blur-md p-5">
          <p className="font-semibold text-foreground">Annuler mon inscription ?</p>
          <p className="mt-1 text-sm text-muted-foreground">
            Ta place sera libérée et tu ne pourras plus accéder à cet événement avec cette inscription.
            Cette action est irréversible.
          </p>
          <div className="mt-4 flex flex-wrap gap-3">
            <button
              className="inline-flex min-h-10 cursor-pointer items-center justify-center rounded border border-danger bg-danger px-5 text-sm font-semibold text-white transition-colors hover:bg-danger/90"
              type="button"
              onClick={() => {
                void handleCancelConfirmed();
              }}
            >
              Confirmer l&apos;annulation
            </button>
            <button
              className="inline-flex min-h-10 cursor-pointer items-center justify-center rounded border border-border px-5 text-sm font-semibold text-foreground transition-colors hover:border-accent"
              type="button"
              onClick={() => setCancelState({ kind: "idle" })}
            >
              Garder mon inscription
            </button>
          </div>
        </div>
      ) : cancelState.kind === "cancelling" ? (
        <p className="text-sm text-muted-foreground">Annulation en cours…</p>
      ) : cancelState.kind === "error" ? (
        <p className="flex items-center gap-2 text-sm text-danger">
          <AlertCircle aria-hidden="true" className="size-4 shrink-0" />
          {cancelState.message}
        </p>
      ) : null}
    </article>
  );
}

// ─── Parsers ──────────────────────────────────────────────────────────────────

function parseSelectionData(payload: unknown): SelectionData | null {
  if (!payload || typeof payload !== "object") return null;
  const data = (payload as { data?: unknown }).data;
  if (!data || typeof data !== "object") return null;
  const d = data as Record<string, unknown>;

  if (
    typeof d.registrationId !== "string" ||
    typeof d.eventId !== "string" ||
    typeof d.eventTitle !== "string" ||
    typeof d.gameSelectionEnabled !== "boolean" ||
    !Array.isArray(d.availableGames) ||
    !Array.isArray(d.slots)
  ) {
    return null;
  }

  return {
    registrationId: d.registrationId,
    eventId: d.eventId,
    eventTitle: d.eventTitle,
    registrationOpen: typeof d.registrationOpen === "boolean" ? d.registrationOpen : true,
    gameSelectionEnabled: d.gameSelectionEnabled,
    maxGamesPerRegistrant:
      typeof d.maxGamesPerRegistrant === "number" ? d.maxGamesPerRegistrant : null,
    slots: (d.slots as unknown[]).flatMap((s) => {
      if (!s || typeof s !== "object") return [];
      const slot = s as Record<string, unknown>;
      if (typeof slot.slotId !== "string" || typeof slot.gameId !== "string") return [];
      return [{
        slotId: slot.slotId,
        gameId: slot.gameId,
        gameName: typeof slot.gameName === "string" ? slot.gameName : slot.gameId,
      }];
    }),
    availableGames: (d.availableGames as unknown[]).flatMap((g) => {
      const game = toAvailableGame(g);
      return game ? [game] : [];
    }),
  };
}

function toAvailableGame(x: unknown): AvailableGame | null {
  if (!x || typeof x !== "object") return null;
  const g = x as Record<string, unknown>;
  if (
    typeof g.id !== "string" ||
    typeof g.name !== "string" ||
    typeof g.slug !== "string" ||
    typeof g.description !== "string" ||
    typeof g.availability !== "string"
  ) {
    return null;
  }
  return {
    id: g.id,
    name: g.name,
    slug: g.slug,
    description: g.description,
    availability: g.availability,
    isApworldReady: g.isApworldReady === true,
    defaultYaml: typeof g.defaultYaml === "string" ? g.defaultYaml : null,
    coverImageUrl: typeof g.coverImageUrl === "string" ? g.coverImageUrl : null,
    coverImageAlt: typeof g.coverImageAlt === "string" ? g.coverImageAlt : null,
  };
}
