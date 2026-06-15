"use client";

import { useEffect, useId, useMemo, useRef, useState } from "react";
import { Gamepad2, Loader2, Search } from "lucide-react";
import { FaSteam } from "react-icons/fa";
import { useAuth } from "@/features/auth/auth-context";
import { saveSteamAccount } from "@/features/auth/steam-account-api";
import { GameCard } from "./game-card";
import { coupleSteamLibrary, type CouplingResult } from "./steam-coupling-api";
import {
  allCategories,
  filterAndSortGames,
  isOwned,
  type AvailabilityFilter,
  type SortOrder,
} from "./games-filter";
import type { PublicGame } from "./public-games-api";

const STORAGE_KEY = "archilan.steamProfile";
const STEAM_PRIVACY_URL = "https://steamcommunity.com/my/edit/settings";

export function GamesCatalog({ initialGames }: { initialGames: PublicGame[] }) {
  const { user, setUser } = useAuth();

  // ── Catalog controls ──────────────────────────────────────────────────────
  const [query, setQuery] = useState("");
  const debouncedQuery = useDebouncedValue(query, 150);
  const [availability, setAvailability] = useState<AvailabilityFilter>("all");
  const [ownedOnly, setOwnedOnly] = useState(false);
  const [sort, setSort] = useState<SortOrder>("name-asc");
  const [categories, setCategories] = useState<string[]>([]);
  const categoryOptions = useMemo(() => allCategories(initialGames), [initialGames]);

  // ── Coupling state ────────────────────────────────────────────────────────
  const [steamInput, setSteamInput] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState<CouplingResult | null>(null);
  const [saveMessage, setSaveMessage] = useState<string | null>(null);
  const dirty = useRef(false);

  const matchedAppIds = useMemo(
    () =>
      result?.outcome === "ok"
        ? new Set(result.matchedGames.map((g) => g.steamAppId))
        : new Set<number>(),
    [result],
  );

  const coupled = matchedAppIds.size > 0;

  // Pre-fill the Steam input from the saved account value or localStorage.
  useEffect(() => {
    if (dirty.current) return;
    const prefill = user?.steamProfile ?? window.localStorage.getItem(STORAGE_KEY) ?? "";
    if (prefill !== "") {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setSteamInput(prefill);
    }
  }, [user]);

  // Drop the owned-only filter if a coupling is cleared/unsuccessful.
  useEffect(() => {
    if (!coupled && ownedOnly) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setOwnedOnly(false);
    }
  }, [coupled, ownedOnly]);

  const visibleGames = useMemo(
    () =>
      filterAndSortGames(
        initialGames,
        { query: debouncedQuery, availability, ownedOnly, sort, categories },
        matchedAppIds,
      ),
    [initialGames, debouncedQuery, availability, ownedOnly, sort, categories, matchedAppIds],
  );

  function toggleCategory(category: string) {
    setCategories((prev) =>
      prev.includes(category) ? prev.filter((c) => c !== category) : [...prev, category],
    );
  }

  async function handleCouple(event: React.FormEvent) {
    event.preventDefault();
    const trimmed = steamInput.trim();
    if (trimmed === "" || submitting) return;

    setSubmitting(true);
    setSaveMessage(null);

    const coupling = await coupleSteamLibrary(trimmed);
    setResult(coupling);

    if (coupling.outcome === "ok" && user === null) {
      window.localStorage.setItem(STORAGE_KEY, trimmed);
    }

    setSubmitting(false);
  }

  async function handleSaveToAccount() {
    const trimmed = steamInput.trim();
    if (trimmed === "") return;
    const saved = await saveSteamAccount(trimmed);
    if (saved.ok && user) {
      setUser({ ...user, steamProfile: trimmed });
      setSaveMessage("Compte Steam enregistré sur ton profil.");
    } else {
      setSaveMessage("Impossible d'enregistrer le compte Steam pour le moment.");
    }
  }

  return (
    <div className="grid gap-8">
      <CouplingForm
        steamInput={steamInput}
        submitting={submitting}
        onChange={(v) => {
          dirty.current = true;
          setSteamInput(v);
        }}
        onSubmit={handleCouple}
      />

      {result ? (
        <CouplingFeedback
          result={result}
          canSave={result.outcome === "ok" && user !== null}
          saveMessage={saveMessage}
          onSave={handleSaveToAccount}
        />
      ) : null}

      <CatalogControls
        availability={availability}
        coupled={coupled}
        ownedOnly={ownedOnly}
        query={query}
        sort={sort}
        onAvailability={setAvailability}
        onOwnedOnly={setOwnedOnly}
        onQuery={setQuery}
        onSort={setSort}
      />

      {categoryOptions.length > 0 ? (
        <CategoryChips options={categoryOptions} selected={categories} onToggle={toggleCategory} />
      ) : null}

      <p className="text-sm text-muted-foreground" role="status">
        {visibleGames.length} jeu{visibleGames.length !== 1 ? "x" : ""}
        {coupled ? ` · ${visibleGames.filter((g) => isOwned(g, matchedAppIds)).length} possédé(s)` : ""}
      </p>

      {visibleGames.length > 0 ? (
        <div className="grid gap-5 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          {visibleGames.map((game) => (
            <GameCard game={game} key={game.id} owned={isOwned(game, matchedAppIds)} />
          ))}
        </div>
      ) : (
        <div className="card-glow rounded-lg border border-border p-10 text-center">
          <Gamepad2 aria-hidden="true" className="mx-auto mb-4 size-10 text-accent-text" />
          <p className="font-heading text-xl font-semibold text-foreground">Aucun jeu trouvé</p>
          <p className="mx-auto mt-3 max-w-md text-sm leading-6 text-muted-foreground">
            Ajuste ta recherche ou tes filtres.
          </p>
        </div>
      )}
    </div>
  );
}

// ── Sub-components ────────────────────────────────────────────────────────────

function CouplingForm({
  steamInput,
  submitting,
  onChange,
  onSubmit,
}: {
  steamInput: string;
  submitting: boolean;
  onChange: (v: string) => void;
  onSubmit: (e: React.FormEvent) => void;
}) {
  const inputId = useId();

  return (
    <section
      aria-labelledby="steam-coupling-heading"
      className="card-glow grid gap-4 rounded-xl border border-border p-6"
    >
      <div>
        <h2
          className="flex items-center gap-2 font-heading text-xl font-bold text-foreground"
          id="steam-coupling-heading"
        >
          <FaSteam aria-hidden="true" size={20} />
          Couple ta bibliothèque Steam
        </h2>
        <p className="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground">
          Renseigne ton compte Steam pour voir, dans le catalogue, les jeux que tu possèdes et qui
          sont jouables aux événements ArchiLAN.
        </p>
      </div>
      <form className="flex flex-col gap-2 sm:flex-row sm:items-start" onSubmit={onSubmit}>
        <div className="flex-1">
          <label className="sr-only" htmlFor={inputId}>
            URL de profil, pseudo Steam, ou SteamID64
          </label>
          <input
            className="min-h-11 w-full rounded border border-border bg-background px-3 text-sm text-foreground outline-none transition-colors focus:border-accent disabled:cursor-not-allowed disabled:opacity-50"
            disabled={submitting}
            id={inputId}
            onChange={(e) => onChange(e.target.value)}
            placeholder="https://steamcommunity.com/id/ton-pseudo"
            type="text"
            value={steamInput}
          />
        </div>
        <button
          className="inline-flex min-h-11 items-center justify-center gap-2 rounded border border-border bg-background px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-50 sm:shrink-0"
          disabled={submitting || steamInput.trim() === ""}
          type="submit"
        >
          {submitting ? <Loader2 aria-hidden="true" className="size-4 animate-spin" /> : null}
          Coupler
        </button>
      </form>
    </section>
  );
}

function CouplingFeedback({
  result,
  canSave,
  saveMessage,
  onSave,
}: {
  result: CouplingResult;
  canSave: boolean;
  saveMessage: string | null;
  onSave: () => void;
}) {
  if (result.outcome === "invalid_input") {
    return (
      <Notice tone="error">
        Profil Steam non reconnu — colle l&apos;URL de ton profil, ton pseudo Steam, ou ton
        SteamID64.
      </Notice>
    );
  }

  if (result.outcome === "steam_error") {
    return <Notice tone="error">Steam est indisponible pour l&apos;instant. Réessaie dans un moment.</Notice>;
  }

  if (result.outcome === "private_profile") {
    return (
      <Notice tone="status">
        Ta bibliothèque Steam est privée. Passe tes «&nbsp;détails de jeu&nbsp;» en public le temps
        du couplage&nbsp;:{" "}
        <a className="underline hover:text-foreground" href={STEAM_PRIVACY_URL} rel="noreferrer" target="_blank">
          réglages Steam
        </a>
        . C&apos;est un réglage Steam, pas une erreur ArchiLAN.
      </Notice>
    );
  }

  return (
    <div className="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-success/40 bg-success/5 p-4">
      <p className="text-sm font-semibold text-foreground" role="status">
        {result.matchedCount} de tes {result.ownedCount} jeux Steam{" "}
        {result.matchedCount > 1 ? "sont jouables" : "est jouable"} à ArchiLAN.
      </p>
      {canSave ? (
        <div className="flex flex-wrap items-center gap-3">
          {saveMessage ? (
            <span className="text-sm text-muted-foreground" role="status">
              {saveMessage}
            </span>
          ) : null}
          <button
            className="inline-flex min-h-9 items-center justify-center rounded border border-border bg-surface px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
            type="button"
            onClick={onSave}
          >
            Enregistrer sur mon compte
          </button>
        </div>
      ) : null}
    </div>
  );
}

function CatalogControls({
  query,
  availability,
  ownedOnly,
  sort,
  coupled,
  onQuery,
  onAvailability,
  onOwnedOnly,
  onSort,
}: {
  query: string;
  availability: AvailabilityFilter;
  ownedOnly: boolean;
  sort: SortOrder;
  coupled: boolean;
  onQuery: (v: string) => void;
  onAvailability: (v: AvailabilityFilter) => void;
  onOwnedOnly: (v: boolean) => void;
  onSort: (v: SortOrder) => void;
}) {
  const selectClass =
    "min-h-11 rounded border border-border bg-background px-3 text-sm text-foreground outline-none transition-colors focus:border-accent";

  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
      <div className="relative max-w-md flex-1">
        <Search
          aria-hidden="true"
          className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
        />
        <input
          aria-label="Rechercher un jeu"
          className="min-h-11 w-full rounded border border-border bg-background py-2 pl-10 pr-4 text-sm outline-none transition-colors focus:border-accent"
          onChange={(e) => onQuery(e.target.value)}
          placeholder="Hollow Knight, Stardew Valley…"
          type="search"
          value={query}
        />
      </div>

      <select
        aria-label="Filtrer par disponibilité"
        className={selectClass}
        onChange={(e) => onAvailability(e.target.value as AvailabilityFilter)}
        value={availability}
      >
        <option value="all">Toutes dispos</option>
        <option value="available">Disponible</option>
        <option value="experimental">Expérimental</option>
      </select>

      <select
        aria-label="Trier"
        className={selectClass}
        onChange={(e) => onSort(e.target.value as SortOrder)}
        value={sort}
      >
        <option value="name-asc">Nom A→Z</option>
        <option value="name-desc">Nom Z→A</option>
      </select>

      <label
        className={`inline-flex min-h-11 items-center gap-2 rounded border border-border px-3 text-sm font-medium ${coupled ? "text-foreground" : "cursor-not-allowed text-muted-foreground/50"}`}
        title={coupled ? undefined : "Couple ta bibliothèque Steam d'abord"}
      >
        <input
          checked={ownedOnly}
          className="size-4 accent-accent"
          disabled={!coupled}
          onChange={(e) => onOwnedOnly(e.target.checked)}
          type="checkbox"
        />
        Mes jeux
      </label>
    </div>
  );
}

function CategoryChips({
  options,
  selected,
  onToggle,
}: {
  options: string[];
  selected: string[];
  onToggle: (category: string) => void;
}) {
  return (
    <div className="flex flex-wrap gap-2" role="group" aria-label="Filtrer par catégorie">
      {options.map((category) => {
        const active = selected.includes(category);
        return (
          <button
            key={category}
            aria-pressed={active}
            className={`inline-flex min-h-9 items-center rounded-full border px-3 text-sm font-medium transition-colors ${
              active
                ? "border-accent bg-accent/15 text-accent-text"
                : "border-border bg-surface text-muted-foreground hover:border-accent hover:text-foreground"
            }`}
            onClick={() => onToggle(category)}
            type="button"
          >
            {category}
          </button>
        );
      })}
    </div>
  );
}

function Notice({ tone, children }: { tone: "error" | "status"; children: React.ReactNode }) {
  return (
    <p
      className="rounded border border-border bg-background p-3 text-sm text-muted-foreground"
      role={tone === "error" ? "alert" : "status"}
    >
      {children}
    </p>
  );
}

// ── Hooks ─────────────────────────────────────────────────────────────────────

function useDebouncedValue<T>(value: T, delayMs: number): T {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const id = setTimeout(() => setDebounced(value), delayMs);
    return () => clearTimeout(id);
  }, [value, delayMs]);

  return debounced;
}
