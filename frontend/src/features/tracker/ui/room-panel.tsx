"use client";

import { Loader2, PlugZap, Unplug } from "lucide-react";
import { useState } from "react";

import { buttonVariants } from "@/components/ui/button";

import type { ApCredentials, ApLinkStatus } from "./ap-link";

/**
 * Autotracking from an Archipelago room (story 54.6): the form, the connection state and what the room changed.
 * The address and the slot name are remembered in the browser; the password never is.
 */
type Props = {
  status: ApLinkStatus;
  notice: string | null;
  disabled: boolean;
  onConnect: (credentials: ApCredentials) => void;
  onDisconnect: () => void;
};

const REMEMBERED_KEY = "tracker:room";

type Remembered = { address: string; slot: string };

function readRemembered(): Remembered {
  try {
    const raw = window.localStorage.getItem(REMEMBERED_KEY);
    const value: unknown = raw === null ? null : JSON.parse(raw);
    if (typeof value === "object" && value !== null && "address" in value && "slot" in value) {
      const { address, slot } = value;
      if (typeof address === "string" && typeof slot === "string") return { address, slot };
    }
  } catch {
    // Storage blocked or broken: start empty.
  }
  return { address: "", slot: "" };
}

function remember(value: Remembered): void {
  try {
    window.localStorage.setItem(REMEMBERED_KEY, JSON.stringify(value));
  } catch {
    // Not remembered, nothing else changes.
  }
}

const INPUT_CLASS =
  "min-h-10 rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent";

export function RoomPanel({ status, notice, disabled, onConnect, onDisconnect }: Props) {
  const [form, setForm] = useState<(Remembered & { password: string }) | null>(null);
  const showForm = form !== null && (status.kind === "idle" || status.kind === "failed");

  function openForm() {
    setForm({ ...readRemembered(), password: "" });
  }

  return (
    <section aria-label="Autotracking Archipelago" className="grid gap-2 rounded-lg border border-border bg-surface px-3 py-2 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <RoomStatus status={status} />
        {status.kind === "idle" || status.kind === "failed" ? (
          showForm ? null : (
            <button className={buttonVariants({ variant: "secondary" })} disabled={disabled} onClick={openForm} type="button">
              <PlugZap aria-hidden="true" className="size-4" />
              Connecter à Archipelago
            </button>
          )
        ) : (
          <button className={buttonVariants({ variant: "secondary" })} onClick={onDisconnect} type="button">
            <Unplug aria-hidden="true" className="size-4" />
            {status.kind === "connected" ? "Déconnecter" : "Annuler"}
          </button>
        )}
      </div>

      {showForm ? (
        <form
          className="grid gap-2 sm:grid-cols-[2fr_1.5fr_1.5fr_auto] sm:items-end"
          onSubmit={(event) => {
            event.preventDefault();
            remember({ address: form.address.trim(), slot: form.slot.trim() });
            onConnect({ address: form.address, slot: form.slot.trim(), password: form.password });
          }}
        >
          <label className="grid gap-1">
            <span className="text-muted-foreground">Adresse de la room</span>
            <input
              autoComplete="off"
              className={INPUT_CLASS}
              onChange={(event) => setForm({ ...form, address: event.target.value })}
              placeholder="archipelago.gg:38281"
              required
              value={form.address}
            />
          </label>
          <label className="grid gap-1">
            <span className="text-muted-foreground">Nom du slot</span>
            <input
              autoComplete="off"
              className={INPUT_CLASS}
              onChange={(event) => setForm({ ...form, slot: event.target.value })}
              required
              value={form.slot}
            />
          </label>
          <label className="grid gap-1">
            <span className="text-muted-foreground">Mot de passe (facultatif)</span>
            <input
              autoComplete="off"
              className={INPUT_CLASS}
              onChange={(event) => setForm({ ...form, password: event.target.value })}
              type="password"
              value={form.password}
            />
          </label>
          <div className="flex gap-2">
            <button className={buttonVariants({ variant: "primary" })} disabled={disabled} type="submit">
              Connecter
            </button>
            <button className={buttonVariants({ variant: "ghost" })} onClick={() => setForm(null)} type="button">
              Fermer
            </button>
          </div>
        </form>
      ) : null}

      {notice !== null ? <p className="text-muted-foreground">{notice}</p> : null}
    </section>
  );
}

function RoomStatus({ status }: { status: ApLinkStatus }) {
  switch (status.kind) {
    case "idle":
      return (
        <p className="text-muted-foreground" role="status">
          Suivi manuel. Reliez le tracker à votre partie pour qu&apos;il se remplisse tout seul.
        </p>
      );
    case "connecting":
      return (
        <p className="flex items-center gap-2 text-foreground" role="status">
          <Loader2 aria-hidden="true" className="size-4 animate-spin" />
          Connexion à la room…
        </p>
      );
    case "connected":
      return (
        <p className="flex items-center gap-2 text-foreground" role="status">
          <span aria-hidden="true" className="size-2.5 rounded-full bg-tracker-normal" />
          Relié à la room, slot {status.slot}. Le tracker suit la partie.
        </p>
      );
    case "reconnecting":
      return (
        <p className="flex items-center gap-2 text-foreground" role="status">
          <Loader2 aria-hidden="true" className="size-4 animate-spin" />
          Connexion perdue, nouvel essai dans {Math.round(status.delayMs / 1000)} s (essai {status.attempt}).
        </p>
      );
    case "failed":
      return (
        <p className="text-danger" role="alert">
          {status.message}
        </p>
      );
  }
}
