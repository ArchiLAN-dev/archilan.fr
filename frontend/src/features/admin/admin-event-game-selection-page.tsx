"use client";

import { ArrowLeft, Check, Gamepad2, ShieldAlert } from "lucide-react";
import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import type { FormEvent } from "react";
import { useRouter } from "next/navigation";

import { ThumbnailPreview } from "@/features/admin/admin-game-library-dashboard";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

type AvailabilityScope = "available" | "available_experimental";

type AvailableGame = {
  id: string;
  name: string;
  slug: string;
  availability: string;
  isApworldReady: boolean;
  coverImageUrl: string | null;
};

type GameSelectionEntry = {
  gameId: string;
  gameName: string;
  gameSlug: string;
};

type GameSelectionConfig = {
  gameSelectionEnabled: boolean;
  gameSelectionMax: number | null;
  selectedGames: GameSelectionEntry[];
  availableGames: AvailableGame[];
};

type PageState =
  | { kind: "loading" }
  | { kind: "ready"; config: GameSelectionConfig; eventTitle: string }
  | { kind: "error"; message: string };

export function AdminEventGameSelectionPage({ eventId }: { eventId: string }) {
  const [state, setState] = useState<PageState>({ kind: "loading" });
  const [enabled, setEnabled] = useState(false);
  const [maxGames, setMaxGames] = useState<number | null>(null);
  const [selectedGameIds, setSelectedGameIds] = useState<Set<string>>(new Set());
  const [scope, setScope] = useState<AvailabilityScope>("available");
  const [search, setSearch] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const router = useRouter();

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        const [configRes, eventRes] = await Promise.all([
          fetch(`${env.apiBaseUrl}/admin/events/${eventId}/game-selection`, { credentials: "include" }),
          fetch(`${env.apiBaseUrl}/admin/events/${eventId}`, { credentials: "include" }),
        ]);

        if (cancelled) return;

        if (!configRes.ok) {
          setState({ kind: "error", message: "Impossible de charger la configuration de sélection." });
          return;
        }

        const configPayload: unknown = await configRes.json();

        if (!isGameSelectionConfigPayload(configPayload)) {
          setState({ kind: "error", message: "Réponse API invalide." });
          return;
        }

        let eventTitle = "";
        if (eventRes.ok) {
          const eventPayload: unknown = await eventRes.json();
          if (isEventPayload(eventPayload)) {
            eventTitle = eventPayload.data.title;
          }
        }

        const config = configPayload.data;
        setState({ kind: "ready", config, eventTitle });
        setEnabled(config.gameSelectionEnabled);
        setMaxGames(config.gameSelectionMax ?? null);
        setSelectedGameIds(new Set(config.selectedGames.map((g) => g.gameId)));
      } catch {
        if (!cancelled) {
          setState({ kind: "error", message: "Impossible de contacter l'API." });
        }
      }
    }

    void load();
    return () => { cancelled = true; };
  }, [eventId]);

  const allGames = state.kind === "ready" ? state.config.availableGames : [];

  const filteredGames = useMemo(() => {
    const scopeFiltered = allGames.filter((g) =>
      scope === "available"
        ? g.availability === "available"
        : g.availability === "available" || g.availability === "experimental",
    );

    if (!search) return scopeFiltered;

    const q = search.toLowerCase();
    return scopeFiltered.filter(
      (g) => g.name.toLowerCase().includes(q) || g.slug.includes(q),
    );
  }, [allGames, scope, search]);

  function toggleGame(gameId: string) {
    setSelectedGameIds((prev) => {
      const next = new Set(prev);
      if (next.has(gameId)) {
        next.delete(gameId);
      } else {
        next.add(gameId);
      }
      return next;
    });
  }

  async function submit(formEvent: FormEvent<HTMLFormElement>) {
    formEvent.preventDefault();
    setError(null);
    setSubmitting(true);

    const games = Array.from(selectedGameIds).map((gameId) => ({ gameId }));

    try {
      const response = await apiFetch(`${env.apiBaseUrl}/admin/events/${eventId}/game-selection`, {
        body: JSON.stringify({ gameSelectionEnabled: enabled, gameSelectionMax: maxGames, games }),
        headers: { "Content-Type": "application/json" },
        method: "PATCH",
      });

      if (!response.ok) {
        setError("Impossible d'enregistrer la configuration.");
        setSubmitting(false);
        return;
      }

      router.push("/admin/evenements");
    } catch {
      setError("Impossible de contacter l'API.");
      setSubmitting(false);
    }
  }

  if (state.kind === "loading") {
    return (
      <section className="grid w-full gap-8 px-4 py-10">
        <div className="animate-pulse">
          <div className="h-4 w-32 rounded bg-surface-2" />
          <div className="mt-6 h-3.5 w-20 rounded bg-surface-2" />
          <div className="mt-2 h-9 w-64 rounded bg-surface-2" />
          <div className="mt-1.5 h-4 w-48 rounded bg-surface-2" />
        </div>
        <div className="h-20 animate-pulse rounded-lg border border-border bg-surface" />
        <div className="h-10 animate-pulse rounded border border-border bg-surface" />
        <div className="animate-pulse overflow-hidden rounded-lg border border-border bg-surface">
          {Array.from({ length: 8 }).map((_, i) => (
            <div className="flex items-center gap-4 border-b border-border px-4 py-3 last:border-b-0" key={i}>
              <div className="flex flex-col gap-1.5 flex-1">
                <div className="h-3.5 rounded bg-surface-2" style={{ width: `${[160, 120, 200, 140, 180, 110, 155, 130][i]}px` }} />
                <div className="h-2.5 w-24 rounded bg-surface-2 opacity-60" />
              </div>
              <div className="h-4 w-16 rounded bg-surface-2" />
              <div className="size-4 rounded bg-surface-2" />
            </div>
          ))}
        </div>
      </section>
    );
  }

  if (state.kind === "error") {
    return (
      <section className="grid w-full gap-8 px-4 py-10">
        <div className="grid justify-items-center gap-3 rounded-lg border border-border bg-surface p-8 text-center">
          <ShieldAlert aria-hidden="true" className="size-8 text-danger" />
          <p className="text-sm text-muted-foreground">{state.message}</p>
          <Link className="text-sm text-accent-text hover:underline" href="/admin/evenements">
            Retour aux événements
          </Link>
        </div>
      </section>
    );
  }

  const selectedCount = selectedGameIds.size;

  return (
    <section className="grid w-full gap-8 px-4 py-10">
      <header>
        <Link
          className="mb-6 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
          href="/admin/evenements"
        >
          <ArrowLeft aria-hidden="true" className="size-3.5" />
          Retour aux événements
        </Link>
        <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">Backoffice</p>
        <div className="flex items-center gap-3">
          <Gamepad2 aria-hidden="true" className="size-6 text-accent-text" />
          <div>
            <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">
              Sélection de jeux
            </h1>
            {state.eventTitle ? (
              <p className="mt-1 text-muted-foreground">{state.eventTitle}</p>
            ) : null}
          </div>
        </div>
      </header>

      <form className="grid gap-6" onSubmit={submit}>
        <div className="rounded-lg border border-border bg-surface p-5">
          <label className="flex cursor-pointer select-none items-center gap-3">
            <Checkbox checked={enabled} onChange={setEnabled} />
            <span className="text-sm font-semibold text-foreground">
              Activer la sélection de jeux pour cet événement
            </span>
          </label>

          {enabled ? (
            <div className="mt-5 border-t border-border pt-5">
              <label className="grid gap-1.5 text-sm font-semibold text-foreground">
                Limite de slots par inscription
                <div className="flex items-center gap-3">
                  <input
                    className="min-h-9 w-28 rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                    min={1}
                    placeholder="Illimité"
                    type="number"
                    value={maxGames ?? ""}
                    onChange={(e) => {
                      const v = parseInt(e.target.value, 10);
                      setMaxGames(Number.isFinite(v) && v > 0 ? v : null);
                    }}
                  />
                  <span className="text-xs font-normal text-muted-foreground">Laisser vide pour illimité</span>
                </div>
              </label>
            </div>
          ) : null}
        </div>

        {enabled ? (
          <div className="grid gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div className="flex items-baseline gap-2">
                <h2 className="text-sm font-semibold text-foreground">Jeux disponibles</h2>
                <span className="text-xs text-muted-foreground">
                  {selectedCount} sélectionné{selectedCount !== 1 ? "s" : ""}
                  {filteredGames.length !== allGames.length
                    ? ` · ${filteredGames.length} affiché${filteredGames.length !== 1 ? "s" : ""} sur ${allGames.length}`
                    : ` · ${allGames.length} au total`}
                </span>
              </div>
              <div className="flex gap-0.5 rounded border border-border p-0.5">
                <button
                  className={`rounded px-2.5 py-1 text-xs font-semibold transition-colors ${scope === "available" ? "bg-accent text-white" : "text-muted-foreground hover:text-foreground"}`}
                  onClick={() => setScope("available")}
                  type="button"
                >
                  Disponibles
                </button>
                <button
                  className={`rounded px-2.5 py-1 text-xs font-semibold transition-colors ${scope === "available_experimental" ? "bg-accent text-white" : "text-muted-foreground hover:text-foreground"}`}
                  onClick={() => setScope("available_experimental")}
                  type="button"
                >
                  + Expérimentaux
                </button>
              </div>
            </div>

            <input
              className="min-h-10 w-full rounded border border-border bg-background px-3 text-sm outline-none focus:border-accent"
              placeholder="Rechercher par nom ou slug…"
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />

            {filteredGames.length === 0 ? (
              <div className="grid justify-items-center gap-3 rounded-lg border border-border bg-surface p-8 text-center">
                <Gamepad2 aria-hidden="true" className="size-8 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">
                  {search ? "Aucun jeu ne correspond à la recherche." : "Aucun jeu dans cette catégorie."}
                </p>
              </div>
            ) : (
              <div className="overflow-x-auto border border-border bg-surface">
                <table className="w-full border-collapse text-left text-sm">
                  <thead className="border-b border-border text-muted-foreground">
                    <tr>
                      <th className="w-16 px-4 py-3"><span className="sr-only">Couverture</span></th>
                      <th className="px-4 py-3 font-medium">Jeu</th>
                      <th className="px-4 py-3 font-medium">Disponibilité</th>
                      <th className="w-px px-4 py-3 font-medium">
                        <span className="sr-only">Sélectionner</span>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredGames.map((game) => {
                      const isSelected = selectedGameIds.has(game.id);
                      return (
                        <tr
                          className={`cursor-pointer border-b border-border last:border-b-0 transition-colors hover:bg-surface-2 ${isSelected ? "bg-accent/10" : ""}`}
                          key={game.id}
                          onClick={() => toggleGame(game.id)}
                        >
                          <td className="px-4 py-2">
                            {game.coverImageUrl ? (
                              <ThumbnailPreview src={game.coverImageUrl} />
                            ) : (
                              <div className="h-10 w-7 rounded border border-border bg-surface-2" />
                            )}
                          </td>
                          <td className="px-4 py-3">
                            <div className="flex flex-col gap-0.5">
                              <span className="font-semibold text-foreground">{game.name}</span>
                              <span className="font-mono text-xs text-muted-foreground">{game.slug}</span>
                            </div>
                          </td>
                          <td className="px-4 py-3">
                            {game.availability === "experimental" ? (
                              <span className="text-xs text-accent-warm">Expérimental</span>
                            ) : (
                              <span className="text-xs text-success">Disponible</span>
                            )}
                          </td>
                          <td className="px-4 py-3 text-right">
                            <Checkbox
                              checked={isSelected}
                              onChange={() => toggleGame(game.id)}
                              onClick={(e: React.MouseEvent) => e.stopPropagation()}
                            />
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        ) : null}

        {error ? (
          <p className="text-sm text-danger" role="alert">{error}</p>
        ) : null}

        <div className="flex justify-end gap-3">
          <Link
            className="inline-flex min-h-11 items-center justify-center rounded border border-border px-4 text-sm font-semibold text-foreground hover:border-accent"
            href="/admin/evenements"
          >
            Annuler
          </Link>
          <button
            className="inline-flex min-h-11 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
            disabled={submitting}
            type="submit"
          >
            {submitting ? "Enregistrement..." : "Enregistrer"}
          </button>
        </div>
      </form>
    </section>
  );
}

function Checkbox({
  checked,
  onChange,
  onClick,
}: {
  checked: boolean;
  onChange: (checked: boolean) => void;
  onClick?: (e: React.MouseEvent) => void;
}) {
  return (
    <span className="relative inline-flex shrink-0">
      <input
        checked={checked}
        className="peer sr-only"
        type="checkbox"
        onChange={(e) => onChange(e.target.checked)}
        onClick={onClick}
      />
      <span className="flex size-5 items-center justify-center rounded border border-border bg-background transition-colors peer-checked:border-accent peer-checked:bg-accent peer-focus-visible:ring-2 peer-focus-visible:ring-accent peer-focus-visible:ring-offset-1 peer-focus-visible:ring-offset-background">
        {checked ? <Check aria-hidden="true" className="size-3 text-white" strokeWidth={3} /> : null}
      </span>
    </span>
  );
}

function isGameSelectionConfigPayload(payload: unknown): payload is { data: GameSelectionConfig } {
  const data =
    payload && typeof payload === "object" && "data" in payload
      ? (payload as { data: unknown }).data
      : null;
  return Boolean(
    data &&
      typeof data === "object" &&
      "gameSelectionEnabled" in data &&
      "selectedGames" in data &&
      "availableGames" in data,
  );
}

function isEventPayload(payload: unknown): payload is { data: { id: string; title: string } } {
  const data =
    payload && typeof payload === "object" && "data" in payload
      ? (payload as { data: unknown }).data
      : null;
  return Boolean(data && typeof data === "object" && "id" in data && "title" in data);
}
