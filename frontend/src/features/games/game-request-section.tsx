"use client";

import { useEffect, useId, useRef, useState } from "react";
import { CheckCircle2, ChevronDown, Loader2, Send, ThumbsUp } from "lucide-react";
import Link from "next/link";
import { useAuth } from "@/features/auth/auth-context";
import {
  cancelGameRequest,
  getCatalogGames,
  getGameRequests,
  submitGameRequest,
  type GameRequestItem,
} from "./game-request-api";

// ---------------------------------------------------------------------------
// Combobox
// ---------------------------------------------------------------------------

function GameCombobox({
  options,
  loadingOptions,
  value,
  onChange,
  disabled,
}: {
  options: string[];
  loadingOptions: boolean;
  value: string;
  onChange: (v: string) => void;
  disabled: boolean;
}) {
  const listId = useId();
  const [query, setQuery] = useState("");
  const [open, setOpen] = useState(false);
  const [activeIdx, setActiveIdx] = useState(-1);
  const wrapperRef = useRef<HTMLDivElement>(null);
  const listRef = useRef<HTMLUListElement>(null);

  // Sync input text when value is cleared externally
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    if (!value) setQuery("");
  }, [value]);

  // Close on outside pointer-down
  useEffect(() => {
    function onDown(e: MouseEvent) {
      if (!wrapperRef.current?.contains(e.target as Node)) setOpen(false);
    }
    document.addEventListener("pointerdown", onDown);
    return () => document.removeEventListener("pointerdown", onDown);
  }, []);

  // Scroll active option into view
  useEffect(() => {
    if (activeIdx < 0) return;
    const item = listRef.current?.children[activeIdx] as HTMLElement | undefined;
    item?.scrollIntoView({ block: "nearest" });
  }, [activeIdx]);

  const filtered =
    query.trim()
      ? options.filter((o) => o.toLowerCase().includes(query.toLowerCase()))
      : options;

  function select(name: string) {
    onChange(name);
    setQuery(name);
    setOpen(false);
    setActiveIdx(-1);
  }

  function handleInputChange(e: React.ChangeEvent<HTMLInputElement>) {
    setQuery(e.target.value);
    if (!e.target.value) onChange("");
    setOpen(true);
    setActiveIdx(-1);
  }

  function handleKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    if (e.key === "Escape") { setOpen(false); return; }
    if (e.key === "ArrowDown") {
      e.preventDefault();
      if (!open) { setOpen(true); setActiveIdx(0); return; }
      setActiveIdx((i) => Math.min(i + 1, filtered.length - 1));
      return;
    }
    if (e.key === "ArrowUp") {
      e.preventDefault();
      setActiveIdx((i) => Math.max(i - 1, 0));
      return;
    }
    if (e.key === "Enter" && activeIdx >= 0 && filtered[activeIdx]) {
      e.preventDefault();
      select(filtered[activeIdx]);
    }
  }

  const showList = open && !disabled;

  return (
    <div className="relative" ref={wrapperRef}>
      <div className="relative">
        <input
          aria-activedescendant={activeIdx >= 0 ? `${listId}-opt-${activeIdx}` : undefined}
          aria-autocomplete="list"
          aria-controls={listId}
          aria-expanded={showList}
          aria-label="Nom du jeu à demander"
          autoComplete="off"
          className="min-h-11 w-full rounded border border-border bg-background py-2 pl-3 pr-9 text-sm text-foreground outline-none transition-colors focus:border-accent disabled:cursor-not-allowed disabled:opacity-50"
          disabled={disabled}
          onChange={handleInputChange}
          onFocus={() => setOpen(true)}
          onKeyDown={handleKeyDown}
          placeholder="Celeste, Metroid Dread, A Short Hike…"
          role="combobox"
          type="text"
          value={query}
        />
        <ChevronDown
          aria-hidden="true"
          className="pointer-events-none absolute right-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
        />
      </div>

      {showList && (
        <ul
          className="absolute z-50 mt-1 max-h-56 w-full overflow-y-auto rounded border border-border bg-background shadow-lg"
          id={listId}
          ref={listRef}
          role="listbox"
        >
          {loadingOptions ? (
            <li className="flex items-center gap-2 px-3 py-2.5 text-sm text-muted-foreground">
              <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
              Chargement du catalogue…
            </li>
          ) : filtered.length > 0 ? (
            filtered.map((name, i) => (
              <li
                aria-selected={name === value}
                className={`cursor-pointer px-3 py-2 text-sm transition-colors ${
                  i === activeIdx
                    ? "bg-accent/10 text-foreground"
                    : name === value
                    ? "bg-surface text-foreground"
                    : "text-muted-foreground hover:bg-surface hover:text-foreground"
                }`}
                id={`${listId}-opt-${i}`}
                key={name}
                onPointerDown={(e) => {
                  e.preventDefault(); // keep input focus
                  select(name);
                }}
                role="option"
              >
                {name}
              </li>
            ))
          ) : query ? (
            <li className="px-3 py-2.5 text-sm text-muted-foreground">
              Aucun jeu correspondant dans le catalogue.
            </li>
          ) : (
            <li className="px-3 py-2.5 text-sm text-muted-foreground">
              Le catalogue est indisponible pour l&apos;instant.
            </li>
          )}
        </ul>
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Section principale
// ---------------------------------------------------------------------------

export function GameRequestSection() {
  const { user, loading: authLoading } = useAuth();

  const [catalogGames, setCatalogGames] = useState<string[]>([]);
  const [loadingCatalog, setLoadingCatalog] = useState(true);

  const [requests, setRequests] = useState<GameRequestItem[]>([]);
  const [loadingList, setLoadingList] = useState(true);

  const [selectedGame, setSelectedGame] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [submitSuccess, setSubmitSuccess] = useState(false);
  const successTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const [votingOn, setVotingOn] = useState<string | null>(null);

  useEffect(() => {
    getCatalogGames()
      .then(setCatalogGames)
      .finally(() => setLoadingCatalog(false));
    getGameRequests()
      .then(setRequests)
      .finally(() => setLoadingList(false));
  }, []);

  useEffect(
    () => () => { if (successTimer.current) clearTimeout(successTimer.current); },
    [],
  );

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!selectedGame || submitting) return;

    setSubmitting(true);
    setSubmitError(null);
    setSubmitSuccess(false);

    const result = await submitGameRequest(selectedGame);
    setSubmitting(false);

    if (result.ok) {
      const normalized = selectedGame.toLowerCase().trim();
      setRequests((prev) => {
        const updated = prev.find((r) => r.normalizedName === normalized)
          ? prev.map((r) =>
              r.normalizedName === normalized
                ? { ...r, voteCount: r.voteCount + 1, hasVoted: true }
                : r,
            )
          : [
              {
                normalizedName: normalized,
                displayName: selectedGame,
                voteCount: 1,
                hasVoted: true,
              },
              ...prev,
            ];
        return [...updated].sort((a, b) => b.voteCount - a.voteCount);
      });
      setSelectedGame("");
      setSubmitSuccess(true);
      if (successTimer.current) clearTimeout(successTimer.current);
      successTimer.current = setTimeout(() => setSubmitSuccess(false), 3000);
    } else if (result.alreadyVoted) {
      setSubmitError("Tu as déjà demandé ce jeu.");
    } else {
      setSubmitError(result.error ?? "Une erreur est survenue.");
    }
  }

  async function handleVoteToggle(item: GameRequestItem) {
    if (!user || votingOn) return;
    setVotingOn(item.normalizedName);

    if (item.hasVoted) {
      const ok = await cancelGameRequest(item.normalizedName);
      if (ok) {
        setRequests((prev) =>
          prev
            .map((r) =>
              r.normalizedName === item.normalizedName
                ? { ...r, voteCount: r.voteCount - 1, hasVoted: false }
                : r,
            )
            .filter((r) => r.voteCount > 0)
            .sort((a, b) => b.voteCount - a.voteCount),
        );
      }
    } else {
      const result = await submitGameRequest(item.displayName);
      if (result.ok) {
        setRequests((prev) =>
          [...prev]
            .map((r) =>
              r.normalizedName === item.normalizedName
                ? { ...r, voteCount: r.voteCount + 1, hasVoted: true }
                : r,
            )
            .sort((a, b) => b.voteCount - a.voteCount),
        );
      }
    }

    setVotingOn(null);
  }

  return (
    <section aria-labelledby="game-requests-heading" className="grid gap-6">
      <div>
        <h2
          className="font-heading text-2xl font-bold text-foreground"
          id="game-requests-heading"
        >
          Suggérer un jeu
        </h2>
        <p className="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground">
          Tu connais un jeu Archipelago que l&apos;on devrait intégrer ? Sélectionne-le dans le
          catalogue pour soumettre une demande - l&apos;équipe s&apos;en sert pour prioriser les
          prochains imports.
        </p>
      </div>

      {!authLoading && (
        user ? (
          <form className="flex flex-col gap-2 sm:flex-row sm:items-start" onSubmit={handleSubmit}>
            <div className="flex flex-1 flex-col gap-1.5">
              <GameCombobox
                disabled={submitting}
                loadingOptions={loadingCatalog}
                onChange={(v) => {
                  setSelectedGame(v);
                  setSubmitError(null);
                }}
                options={catalogGames}
                value={selectedGame}
              />
              {submitError && (
                <p className="text-xs text-red-400" role="alert">
                  {submitError}
                </p>
              )}
              {submitSuccess && (
                <p
                  className="flex items-center gap-1 text-xs text-success"
                  role="status"
                >
                  <CheckCircle2 aria-hidden="true" className="size-3.5" />
                  Demande enregistrée !
                </p>
              )}
            </div>
            <button
              className="inline-flex min-h-11 items-center justify-center gap-2 rounded border border-border bg-background px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-50 sm:shrink-0"
              disabled={submitting || !selectedGame}
              type="submit"
            >
              {submitting ? (
                <Loader2 aria-hidden="true" className="size-4 animate-spin" />
              ) : (
                <Send aria-hidden="true" className="size-4" />
              )}
              Demander
            </button>
          </form>
        ) : (
          <p className="text-sm text-muted-foreground">
            <Link
              className="underline transition-colors hover:text-foreground"
              href="/connexion"
            >
              Connecte-toi
            </Link>{" "}
            pour suggérer un jeu ou voter pour une demande existante.
          </p>
        )
      )}

      {loadingList ? (
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden="true" className="size-4 animate-spin" />
          Chargement…
        </div>
      ) : requests.length > 0 ? (
        <ul className="grid gap-2">
          {requests.map((item) => {
            const isToggling = votingOn === item.normalizedName;
            return (
              <li
                className="flex items-center justify-between gap-4 rounded-lg border border-border bg-surface px-4 py-3"
                key={item.normalizedName}
              >
                <span className="truncate text-sm font-medium text-foreground">
                  {item.displayName}
                </span>
                <div className="flex shrink-0 items-center gap-3">
                  <span className="text-xs tabular-nums text-muted-foreground">
                    {item.voteCount} vote{item.voteCount > 1 ? "s" : ""}
                  </span>
                  {user && (
                    <button
                      aria-label={
                        item.hasVoted
                          ? `Annuler ta demande pour ${item.displayName}`
                          : `Voter pour ${item.displayName}`
                      }
                      aria-pressed={item.hasVoted}
                      className={`inline-flex items-center gap-1.5 rounded border px-2.5 py-1 text-xs font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${
                        item.hasVoted
                          ? "border-accent/50 bg-accent/10 text-accent hover:bg-accent/20"
                          : "border-border bg-background text-muted-foreground hover:border-accent hover:text-foreground"
                      }`}
                      disabled={!!votingOn}
                      onClick={() => handleVoteToggle(item)}
                      type="button"
                    >
                      {isToggling ? (
                        <Loader2 aria-hidden="true" className="size-3 animate-spin" />
                      ) : (
                        <ThumbsUp aria-hidden="true" className="size-3" />
                      )}
                      {item.hasVoted ? "Demandé" : "+1"}
                    </button>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      ) : (
        <p className="rounded-lg border border-border bg-surface px-4 py-6 text-center text-sm text-muted-foreground">
          Aucune demande pour l&apos;instant. Sois le premier à en soumettre une !
        </p>
      )}
    </section>
  );
}
