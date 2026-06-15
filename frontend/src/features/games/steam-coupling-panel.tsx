"use client";

import { useEffect, useId, useRef, useState } from "react";
import { Loader2 } from "lucide-react";
import { FaSteam } from "react-icons/fa";
import { useAuth } from "@/features/auth/auth-context";
import { saveSteamAccount } from "@/features/auth/steam-account-api";
import { GameCard } from "./game-card";
import { coupleSteamLibrary, type CoupledGame, type CouplingResult } from "./steam-coupling-api";
import type { PublicGame } from "./public-games-api";

const STORAGE_KEY = "archilan.steamProfile";
const STEAM_PRIVACY_URL = "https://steamcommunity.com/my/edit/settings";

function asPublicGame(game: CoupledGame): PublicGame {
  return {
    id: game.id,
    name: game.name,
    slug: game.slug,
    description: "",
    coverImageUrl: game.coverImageUrl,
    coverImageAlt: game.name,
    availability: game.availability === "experimental" ? "experimental" : "available",
    supportedEventTypes: [],
    steamAppId: game.steamAppId,
  };
}

export function SteamCouplingPanel() {
  const { user, setUser } = useAuth();
  const inputId = useId();

  const [value, setValue] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState<CouplingResult | null>(null);
  const [saveMessage, setSaveMessage] = useState<string | null>(null);
  const dirty = useRef(false);

  // Pre-fill from the saved account value (logged-in) or localStorage (anonymous),
  // without clobbering anything the user has started typing.
  useEffect(() => {
    if (dirty.current) return;
    const prefill = user?.steamProfile ?? window.localStorage.getItem(STORAGE_KEY) ?? "";
    if (prefill !== "") {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setValue(prefill);
    }
  }, [user]);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    const trimmed = value.trim();
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
    const trimmed = value.trim();
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
    <section
      aria-labelledby="steam-coupling-heading"
      className="card-glow grid gap-5 rounded-xl border border-border p-6"
    >
      <div>
        <h2
          className="flex items-center gap-2 font-heading text-2xl font-bold text-foreground"
          id="steam-coupling-heading"
        >
          <FaSteam aria-hidden="true" size={22} />
          Couple ta bibliothèque Steam
        </h2>
        <p className="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground">
          Renseigne ton compte Steam pour voir, parmi les jeux que tu possèdes, lesquels sont
          jouables aux événements ArchiLAN.
        </p>
      </div>

      <form className="flex flex-col gap-2 sm:flex-row sm:items-start" onSubmit={handleSubmit}>
        <div className="flex-1">
          <label className="sr-only" htmlFor={inputId}>
            URL de profil, pseudo Steam, ou SteamID64
          </label>
          <input
            className="min-h-11 w-full rounded border border-border bg-background px-3 text-sm text-foreground outline-none transition-colors focus:border-accent disabled:cursor-not-allowed disabled:opacity-50"
            disabled={submitting}
            id={inputId}
            onChange={(event) => {
              dirty.current = true;
              setValue(event.target.value);
            }}
            placeholder="https://steamcommunity.com/id/ton-pseudo"
            type="text"
            value={value}
          />
        </div>
        <button
          className="inline-flex min-h-11 items-center justify-center gap-2 rounded border border-border bg-background px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-50 sm:shrink-0"
          disabled={submitting || value.trim() === ""}
          type="submit"
        >
          {submitting ? <Loader2 aria-hidden="true" className="size-4 animate-spin" /> : null}
          Coupler
        </button>
      </form>

      {result ? <CouplingOutcome result={result} /> : null}

      {result?.outcome === "ok" && user !== null ? (
        <div className="flex flex-wrap items-center gap-3">
          <button
            className="inline-flex min-h-9 items-center justify-center rounded border border-border bg-surface px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
            type="button"
            onClick={handleSaveToAccount}
          >
            Enregistrer sur mon compte
          </button>
          {saveMessage ? (
            <p className="text-sm text-muted-foreground" role="status">
              {saveMessage}
            </p>
          ) : null}
        </div>
      ) : null}
    </section>
  );
}

function CouplingOutcome({ result }: { result: CouplingResult }) {
  if (result.outcome === "invalid_input") {
    return (
      <p className="rounded border border-border bg-background p-3 text-sm text-muted-foreground" role="alert">
        Profil Steam non reconnu — colle l&apos;URL de ton profil, ton pseudo Steam, ou ton
        SteamID64.
      </p>
    );
  }

  if (result.outcome === "steam_error") {
    return (
      <p className="rounded border border-border bg-background p-3 text-sm text-muted-foreground" role="alert">
        Steam est indisponible pour l&apos;instant. Réessaie dans un moment.
      </p>
    );
  }

  if (result.outcome === "private_profile") {
    return (
      <p className="rounded border border-border bg-background p-3 text-sm text-muted-foreground" role="status">
        Ta bibliothèque Steam est privée. Passe tes «&nbsp;détails de jeu&nbsp;» en public le temps
        du couplage&nbsp;:{" "}
        <a
          className="underline transition-colors hover:text-foreground"
          href={STEAM_PRIVACY_URL}
          rel="noreferrer"
          target="_blank"
        >
          réglages de confidentialité Steam
        </a>
        . C&apos;est un réglage Steam, pas une erreur ArchiLAN.
      </p>
    );
  }

  return (
    <div className="grid gap-5">
      <p className="text-sm font-semibold text-foreground" role="status">
        {result.matchedCount} de tes {result.ownedCount} jeux Steam{" "}
        {result.matchedCount > 1 ? "sont jouables" : "est jouable"} à ArchiLAN.
      </p>

      {result.matchedCount > 0 ? (
        <div className="grid gap-5 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          {result.matchedGames.map((game) => (
            <GameCard game={asPublicGame(game)} key={game.id} owned />
          ))}
        </div>
      ) : (
        <p className="text-sm leading-6 text-muted-foreground">
          Aucun de tes jeux Steam n&apos;est encore supporté — la bibliothèque s&apos;enrichit
          régulièrement.
        </p>
      )}
    </div>
  );
}
