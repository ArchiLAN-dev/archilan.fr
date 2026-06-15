"use client";

import { useCallback, useEffect, useId, useMemo, useRef, useState } from "react";
import { Check, Gamepad2, Loader2, Pencil, Search } from "lucide-react";
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
  const { user, setUser, loading: authLoading } = useAuth();

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
  const [editing, setEditing] = useState(true);
  const [saveMessage, setSaveMessage] = useState<string | null>(null);
  const dirty = useRef(false);
  const autoCoupled = useRef(false);

  const matchedAppIds = useMemo(
    () =>
      result?.outcome === "ok"
        ? new Set(result.matchedGames.map((g) => g.steamAppId))
        : new Set<number>(),
    [result],
  );

  const coupled = matchedAppIds.size > 0;
  const alreadySaved =
    user !== null && "" !== steamInput.trim() && user.steamProfile === steamInput.trim();

  const couple = useCallback(
    async (rawValue: string) => {
      const trimmed = rawValue.trim();
      if ("" === trimmed) return;

      setSubmitting(true);
      setSaveMessage(null);

      const coupling = await coupleSteamLibrary(trimmed);
      setResult(coupling);

      if (coupling.outcome === "ok") {
        setEditing(false);
        if (user === null) window.localStorage.setItem(STORAGE_KEY, trimmed);
      }

      setSubmitting(false);
    },
    [user],
  );

  // Pre-fill from the saved account value (or localStorage) and auto-couple once,
  // after auth has settled so a logged-in member uses their saved profile.
  useEffect(() => {
    if (authLoading || autoCoupled.current || dirty.current) return;
    const prefill = user?.steamProfile ?? window.localStorage.getItem(STORAGE_KEY) ?? "";
    if ("" === prefill) return;

    autoCoupled.current = true;
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setSteamInput(prefill);
    void couple(prefill);
  }, [authLoading, user, couple]);

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

  function handleCouple(event: React.FormEvent) {
    event.preventDefault();
    if (submitting) return;
    void couple(steamInput);
  }

  async function handleSaveToAccount() {
    const trimmed = steamInput.trim();
    if ("" === trimmed) return;
    const saved = await saveSteamAccount(trimmed);
    if (saved.ok && user) {
      // alreadySaved flips to true, switching the control to a "saved" state.
      setUser({ ...user, steamProfile: trimmed });
    } else {
      setSaveMessage("Impossible d'enregistrer le compte Steam pour le moment.");
    }
  }

  return (
    <div className="grid gap-8">
      <SteamCoupling
        view={result?.outcome === "ok" && !editing ? "summary" : "form"}
        steamInput={steamInput}
        submitting={submitting}
        result={result}
        loggedIn={user !== null}
        alreadySaved={alreadySaved}
        saveMessage={saveMessage}
        onChange={(v) => {
          dirty.current = true;
          setSteamInput(v);
        }}
        onSubmit={handleCouple}
        onEdit={() => setEditing(true)}
        onCancel={() => setEditing(false)}
        onSave={handleSaveToAccount}
      />

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

function SteamCoupling({
  view,
  steamInput,
  submitting,
  result,
  loggedIn,
  alreadySaved,
  saveMessage,
  onChange,
  onSubmit,
  onEdit,
  onCancel,
  onSave,
}: {
  view: "form" | "summary";
  steamInput: string;
  submitting: boolean;
  result: CouplingResult | null;
  loggedIn: boolean;
  alreadySaved: boolean;
  saveMessage: string | null;
  onChange: (v: string) => void;
  onSubmit: (e: React.FormEvent) => void;
  onEdit: () => void;
  onCancel: () => void;
  onSave: () => void;
}) {
  const inputId = useId();
  const hasCoupling = result?.outcome === "ok";

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
          {"summary" === view ? "Ta bibliothèque Steam" : "Couple ta bibliothèque Steam"}
        </h2>
        {"form" === view ? (
          <p className="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground">
            Renseigne ton compte Steam pour voir, dans le catalogue, les jeux que tu possèdes et qui
            sont jouables aux événements ArchiLAN.
          </p>
        ) : null}
      </div>

      {result && !hasCoupling ? <CouplingNotice outcome={result.outcome} /> : null}

      {"summary" === view && null !== result ? (
        <div className="grid gap-3">
          <div className="flex flex-wrap items-center gap-3">
            <span className="text-sm text-foreground">
              Bibliothèque de <span className="font-semibold text-accent-text">{steamInput}</span>
            </span>
            <button
              className="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground underline-offset-2 transition-colors hover:text-foreground hover:underline"
              type="button"
              onClick={onEdit}
            >
              <Pencil aria-hidden="true" className="size-3.5" />
              Modifier
            </button>
          </div>

          <p className="text-sm font-semibold text-foreground" role="status">
            {result.matchedCount} de tes {result.ownedCount} jeux Steam{" "}
            {result.matchedCount > 1 ? "sont jouables" : "est jouable"} à ArchiLAN.
          </p>

          {loggedIn ? (
            alreadySaved ? (
              <p className="inline-flex items-center gap-1.5 text-sm text-success">
                <Check aria-hidden="true" className="size-4" />
                Enregistré sur ton compte
              </p>
            ) : (
              <div className="flex flex-wrap items-center gap-3">
                <button
                  className="inline-flex min-h-9 items-center justify-center rounded border border-border bg-surface px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
                  type="button"
                  onClick={onSave}
                >
                  Enregistrer sur mon compte
                </button>
                {saveMessage ? (
                  <span className="text-sm text-danger" role="alert">
                    {saveMessage}
                  </span>
                ) : null}
              </div>
            )
          ) : (
            <p className="text-sm text-muted-foreground">
              <a className="underline transition-colors hover:text-foreground" href="/connexion">
                Connecte-toi
              </a>{" "}
              pour enregistrer ton compte Steam et retrouver tes jeux à chaque visite.
            </p>
          )}
        </div>
      ) : (
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
            disabled={submitting || "" === steamInput.trim()}
            type="submit"
          >
            {submitting ? <Loader2 aria-hidden="true" className="size-4 animate-spin" /> : null}
            Coupler
          </button>
          {hasCoupling ? (
            <button
              className="inline-flex min-h-11 items-center justify-center rounded px-3 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground sm:shrink-0"
              type="button"
              onClick={onCancel}
            >
              Annuler
            </button>
          ) : null}
        </form>
      )}
    </section>
  );
}

function CouplingNotice({ outcome }: { outcome: CouplingResult["outcome"] }) {
  if ("invalid_input" === outcome) {
    return (
      <Notice tone="error">
        Profil Steam non reconnu — colle l&apos;URL de ton profil, ton pseudo Steam, ou ton
        SteamID64.
      </Notice>
    );
  }

  if ("steam_error" === outcome) {
    return <Notice tone="error">Steam est indisponible pour l&apos;instant. Réessaie dans un moment.</Notice>;
  }

  if ("private_profile" === outcome) {
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

  return null;
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
