"use client";

import { useId } from "react";
import { Loader2, Pencil } from "lucide-react";
import { FaSteam } from "react-icons/fa";
import type { CouplingResult } from "./steam-coupling-api";

const STEAM_PRIVACY_URL = "https://steamcommunity.com/my/edit/settings";

export type SteamCouplingProps = {
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
};

export function SteamCoupling({
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
}: SteamCouplingProps) {
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
            alreadySaved ? null : (
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
        Profil Steam non reconnu - colle l&apos;URL de ton profil, ton pseudo Steam, ou ton
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
