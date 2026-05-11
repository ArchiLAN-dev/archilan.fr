"use client";

import { Gamepad2, Pencil, Plus, ShieldAlert, Trash2 } from "lucide-react";
import Link from "next/link";
import { createPortal } from "react-dom";
import { useEffect, useMemo, useRef, useState } from "react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

type GameAvailability = "available" | "unavailable" | "experimental";

type AdminGame = {
  id: string;
  name: string;
  slug: string;
  coverImageUrl: string | null;
  availability: GameAvailability;
  isYamlReady: boolean;
  usageCount: number;
};

type DashboardState =
  | { kind: "loading" }
  | { kind: "ready"; games: AdminGame[] }
  | { kind: "denied"; message: string }
  | { kind: "error"; message: string };

type AvailabilityFilter = GameAvailability | "all";
type YamlFilter = "all" | "ready" | "not_ready";

export function AdminGameLibraryDashboard() {
  const [state, setState] = useState<DashboardState>({ kind: "loading" });
  const [flashMessage, setFlashMessage] = useState<{ kind: "success" | "error"; text: string } | null>(null);
  const [search, setSearch] = useState("");
  const [availabilityFilter, setAvailabilityFilter] = useState<AvailabilityFilter>("all");
  const [yamlFilter, setYamlFilter] = useState<YamlFilter>("all");

  useEffect(() => {
    let cancelled = false;

    async function loadGames() {
      try {
        const response = await apiFetch(`${env.apiBaseUrl}/admin/games`);

        if (cancelled) return;

        if (response.status === 401 || response.status === 403) {
          setState({ kind: "denied", message: "Accès réservé aux admins ArchiLAN." });
          return;
        }

        if (!response.ok) {
          setState({ kind: "error", message: "Impossible de charger la bibliothèque de jeux." });
          return;
        }

        const payload: unknown = await response.json();
        setState({
          kind: "ready",
          games: isGameListPayload(payload) ? payload.data.sort(sortGames) : [],
        });
      } catch {
        if (!cancelled) setState({ kind: "error", message: "Impossible de contacter l'API jeux." });
      }
    }

    void loadGames();
    return () => { cancelled = true; };
  }, []);

  const filteredGames = useMemo(() => {
    if (state.kind !== "ready") return [];

    return state.games.filter((game) => {
      const q = search.toLowerCase();
      const matchesSearch = !search || game.name.toLowerCase().includes(q) || game.slug.includes(q);
      const matchesAvailability = availabilityFilter === "all" || game.availability === availabilityFilter;
      const matchesYaml =
        yamlFilter === "all" ||
        (yamlFilter === "ready" ? game.isYamlReady : !game.isYamlReady);

      return matchesSearch && matchesAvailability && matchesYaml;
    });
  }, [state, search, availabilityFilter, yamlFilter]);

  async function deleteGame(game: AdminGame) {
    if (!window.confirm(`Supprimer « ${game.name} » de la bibliothèque ?`)) return;

    const response = await apiFetch(`${env.apiBaseUrl}/admin/games/${game.id}`, {
      method: "DELETE",
    });

    if (!response.ok) {
      setFlashMessage({ kind: "error", text: "Suppression impossible : le jeu est peut-être déjà utilisé." });
      return;
    }

    if (state.kind === "ready") {
      setState({ kind: "ready", games: state.games.filter((item) => item.id !== game.id) });
    }

    setFlashMessage({ kind: "success", text: "Jeu supprimé." });
  }

  return (
    <section className="grid w-full gap-8 px-4 py-10">
      <header>
        <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">Backoffice</p>
        <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
          <div>
            <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">
              Bibliothèque de jeux
            </h1>
            <p className="mt-3 max-w-2xl text-muted-foreground">
              Maintiens les jeux Archipelago disponibles pour les futures configurations d&apos;événements.
            </p>
          </div>
          <Link
            className="inline-flex min-h-11 items-center justify-center gap-2 rounded bg-accent px-5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
            href="/admin/jeux/nouveau"
          >
            <Plus aria-hidden="true" className="size-4" />
            Nouveau jeu
          </Link>
        </div>
      </header>

      {flashMessage ? (
        <p
          className={`border p-3 text-sm ${flashMessage.kind === "success" ? "border-success/50 text-success" : "border-danger/50 text-danger"}`}
          role={flashMessage.kind === "success" ? "status" : "alert"}
        >
          {flashMessage.text}
        </p>
      ) : null}

      <div className="flex flex-wrap gap-3">
        <input
          className="min-h-10 flex-1 rounded border border-border bg-background px-3 text-sm outline-none focus:border-accent"
          placeholder="Rechercher par nom ou slug…"
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <select
          className="min-h-10 rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent"
          value={availabilityFilter}
          onChange={(e) => setAvailabilityFilter(e.target.value as AvailabilityFilter)}
        >
          <option value="all">Toutes disponibilités</option>
          <option value="available">Disponible</option>
          <option value="experimental">Expérimental</option>
          <option value="unavailable">Indisponible</option>
        </select>
        <select
          className="min-h-10 rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent"
          value={yamlFilter}
          onChange={(e) => setYamlFilter(e.target.value as YamlFilter)}
        >
          <option value="all">Tout statut YAML</option>
          <option value="ready">YAML configuré</option>
          <option value="not_ready">YAML manquant</option>
        </select>
      </div>

      {state.kind === "ready" ? (
        <p className="text-xs text-muted-foreground">
          {filteredGames.length} jeu{filteredGames.length !== 1 ? "x" : ""}
          {state.games.length !== filteredGames.length ? ` sur ${state.games.length}` : ""}
        </p>
      ) : null}

      <GameList games={filteredGames} state={state} onDelete={deleteGame} />
    </section>
  );
}

function GameList({
  games,
  state,
  onDelete,
}: {
  games: AdminGame[];
  state: DashboardState;
  onDelete: (game: AdminGame) => void;
}) {
  if (state.kind === "loading") {
    return (
      <div className="overflow-x-auto border border-border bg-surface">
        <table className="w-full min-w-[600px] border-collapse text-left text-sm">
          <thead className="border-b border-border text-muted-foreground">
            <tr>
              <th className="px-4 py-3 font-medium">Jeu</th>
              <th className="px-4 py-3 font-medium">Disponibilité</th>
              <th className="px-4 py-3 font-medium">YAML</th>
              <th className="px-4 py-3 font-medium">Utilisations</th>
              <th className="px-4 py-3 font-medium">Actions</th>
            </tr>
          </thead>
          <tbody>
            {Array.from({ length: 5 }).map((_, i) => (
              <tr className="animate-pulse border-b border-border last:border-b-0" key={i}>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-3">
                    <div className="h-10 w-7 shrink-0 rounded bg-surface-2" />
                    <div className="flex flex-col gap-1.5">
                      <div className="h-3.5 rounded bg-surface-2" style={{ width: `${[120, 96, 140, 108, 88][i]}px` }} />
                      <div className="h-2.5 w-20 rounded bg-surface-2 opacity-50" />
                    </div>
                  </div>
                </td>
                <td className="px-4 py-3">
                  <div className="h-3 w-20 rounded bg-surface-2" />
                </td>
                <td className="px-4 py-3">
                  <div className="h-5 w-14 rounded bg-surface-2" />
                </td>
                <td className="px-4 py-3">
                  <div className="h-3 w-6 rounded bg-surface-2" />
                </td>
                <td className="px-4 py-3">
                  <div className="flex gap-2">
                    <div className="h-9 w-24 rounded bg-surface-2" />
                    <div className="h-9 w-20 rounded bg-surface-2" />
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  }

  if (state.kind === "denied" || state.kind === "error") {
    return (
      <div className="grid justify-items-center gap-3 border border-border bg-surface p-8 text-center">
        <ShieldAlert aria-hidden="true" className="size-8 text-danger" />
        <h2 className="font-heading text-2xl font-semibold text-foreground">
          {state.kind === "denied" ? "Accès admin requis" : "Jeux indisponibles"}
        </h2>
        <p className="max-w-md text-sm leading-6 text-muted-foreground">{state.message}</p>
      </div>
    );
  }

  if (games.length === 0) {
    return (
      <div className="grid justify-items-center gap-3 border border-border bg-surface p-8 text-center">
        <Gamepad2 aria-hidden="true" className="size-8 text-accent-text" />
        <h2 className="font-heading text-2xl font-semibold text-foreground">Aucun jeu trouvé</h2>
        <p className="max-w-md text-sm leading-6 text-muted-foreground">
          Ajuste les filtres ou ajoute le premier jeu Archipelago.
        </p>
      </div>
    );
  }

  return (
    <div className="overflow-x-auto border border-border bg-surface">
      <table className="w-full min-w-[600px] border-collapse text-left text-sm">
        <thead className="border-b border-border text-muted-foreground">
          <tr>
            <th className="px-4 py-3 font-medium">Jeu</th>
            <th className="px-4 py-3 font-medium">Disponibilité</th>
            <th className="px-4 py-3 font-medium">YAML</th>
            <th className="px-4 py-3 font-medium">Utilisations</th>
            <th className="px-4 py-3 font-medium">Actions</th>
          </tr>
        </thead>
        <tbody>
          {games.map((game) => (
            <tr className="border-b border-border last:border-b-0" key={game.id}>
              <td className="px-4 py-3">
                <div className="flex items-center gap-3">
                  {game.coverImageUrl ? (
                    <ThumbnailPreview src={game.coverImageUrl} />
                  ) : (
                    <div className="flex h-10 w-7 shrink-0 items-center justify-center rounded bg-surface-2">
                      <Gamepad2 aria-hidden="true" className="size-4 text-muted-foreground" />
                    </div>
                  )}
                  <div>
                    <p className="font-semibold text-foreground">{game.name}</p>
                    <p className="font-mono text-xs text-muted-foreground">{game.slug}</p>
                  </div>
                </div>
              </td>
              <td className="px-4 py-4 text-muted-foreground">{availabilityLabel(game.availability)}</td>
              <td className="px-4 py-3">
                {game.isYamlReady ? (
                  <span className="inline-flex items-center rounded border border-success/50 bg-success/10 px-2 py-0.5 text-xs font-semibold text-success">
                    Prêt
                  </span>
                ) : (
                  <span className="inline-flex items-center rounded border border-warning/50 bg-warning/10 px-2 py-0.5 text-xs font-semibold text-warning">
                    Manquant
                  </span>
                )}
              </td>
              <td className="px-4 py-4 text-muted-foreground">{game.usageCount}</td>
              <td className="px-4 py-3">
                <div className="flex flex-wrap gap-2">
                  <Link
                    className="inline-flex min-h-9 items-center justify-center gap-1.5 rounded border border-border px-3 text-xs font-semibold text-foreground transition-colors hover:border-accent"
                    href={`/admin/jeux/${game.id}`}
                  >
                    <Pencil aria-hidden="true" className="size-3" />
                    Configurer
                  </Link>
                  <button
                    className="inline-flex min-h-9 items-center justify-center gap-1.5 rounded border border-border px-3 text-xs font-semibold text-foreground transition-colors hover:border-danger"
                    type="button"
                    onClick={() => onDelete(game)}
                  >
                    <Trash2 aria-hidden="true" className="size-3" />
                    Supprimer
                  </button>
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export function ThumbnailPreview({ src }: { src: string }) {
  const ref = useRef<HTMLDivElement>(null);
  const [style, setStyle] = useState<React.CSSProperties | null>(null);

  function show() {
    if (!ref.current) return;
    const rect = ref.current.getBoundingClientRect();
    const previewW = 192;
    const left =
      rect.right + 12 + previewW > window.innerWidth
        ? rect.left - previewW - 12
        : rect.right + 12;
    setStyle({
      position: "fixed",
      top: rect.top + rect.height / 2,
      left,
      transform: "translateY(-50%)",
      zIndex: 9999,
      pointerEvents: "none",
    });
  }

  return (
    <div ref={ref} className="inline-block shrink-0" onMouseEnter={show} onMouseLeave={() => setStyle(null)}>
      <img
        alt=""
        className="h-10 w-7 cursor-zoom-in rounded object-cover transition-opacity hover:opacity-80"
        loading="lazy"
        src={src}
      />
      {style
        ? createPortal(
            <div aria-hidden="true" style={style}>
              <div className="overflow-hidden rounded-xl border border-border shadow-[0_16px_48px_rgba(0,0,0,0.7)]" style={{ animation: "thumbnail-pop 120ms ease-out both" }}>
                <img alt="" className="block h-64 w-auto object-cover" src={src} />
              </div>
            </div>,
            document.body,
          )
        : null}
    </div>
  );
}

function availabilityLabel(availability: GameAvailability) {
  return {
    available: "Disponible",
    experimental: "Expérimental",
    unavailable: "Indisponible",
  }[availability];
}

function sortGames(a: AdminGame, b: AdminGame) {
  return a.name.localeCompare(b.name, "fr");
}

function isGameListPayload(payload: unknown): payload is { data: AdminGame[] } {
  return Boolean(
    payload &&
      typeof payload === "object" &&
      "data" in payload &&
      Array.isArray((payload as { data: unknown }).data),
  );
}
