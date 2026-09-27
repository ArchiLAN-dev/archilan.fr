"use client";

import { useId, useState } from "react";

import type { BlockedSanction, ModerationContactMessage } from "./moderation-contact-api";

const MAX_LENGTH = 2000;

const DAY = new Intl.DateTimeFormat("fr-FR", { timeZone: "Europe/Paris", day: "2-digit", month: "2-digit", year: "numeric" });
const MOMENT = new Intl.DateTimeFormat("fr-FR", { timeZone: "Europe/Paris", dateStyle: "long", timeStyle: "short" });

/** La sanction d'un membre bloqué, en une phrase. */
export function sanctionLine({ status, reason, suspendedUntil }: BlockedSanction): string {
  const head =
    status === "banned"
      ? "Ton compte a été banni."
      : `Ton compte est suspendu jusqu'au ${suspendedUntil !== null ? DAY.format(new Date(suspendedUntil)) : "une date ultérieure"}.`;
  return reason !== null && reason !== "" ? `${head} Motif : ${reason}` : head;
}

/**
 * Story 39.2 : l'échange du membre sanctionné avec la modération. Composant de rendu : le chargement et
 * l'envoi sont faits par le conteneur.
 */
export function ModerationContactThread({
  intro,
  messages,
  onSend,
  sending,
  sent,
  error,
}: {
  intro: string | null;
  messages: ModerationContactMessage[];
  onSend: (body: string) => void;
  sending: boolean;
  sent: boolean;
  error: string | null;
}) {
  const fieldId = useId();
  const [body, setBody] = useState("");

  function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const trimmed = body.trim();
    if (trimmed === "") return;
    onSend(trimmed);
    setBody("");
  }

  return (
    <section aria-labelledby={`${fieldId}-title`} className="grid gap-4 rounded-lg border border-border bg-surface p-5">
      <div className="grid gap-1">
        <h2 className="font-heading text-lg font-semibold text-foreground" id={`${fieldId}-title`}>
          Contacter la modération
        </h2>
        {intro !== null ? <p className="text-sm text-foreground">{intro}</p> : null}
        <p className="text-sm text-muted-foreground">
          Une question, une contestation, une demande ? L&apos;équipe de modération lit ton message et te répond.
        </p>
      </div>

      {messages.length > 0 ? (
        <ul className="grid gap-2">
          {messages.map((message) => (
            <li
              className={`grid gap-1 rounded border p-3 ${message.author === "staff" ? "border-accent/40 bg-accent/5" : "border-border bg-background"}`}
              key={message.id}
            >
              <span className="text-xs text-muted-foreground">
                {message.author === "staff" ? "Réponse de la modération" : "Envoyé"} le {MOMENT.format(new Date(message.createdAt))}
              </span>
              <p className="whitespace-pre-line text-sm text-foreground">{message.body}</p>
            </li>
          ))}
        </ul>
      ) : null}

      {sent ? (
        <p className="text-sm text-success" role="status">
          Message envoyé à la modération.
        </p>
      ) : null}
      {error !== null ? (
        <p className="text-sm text-danger" role="alert">
          {error}
        </p>
      ) : null}

      <form className="grid gap-3" onSubmit={handleSubmit}>
        <label className="text-sm font-semibold text-foreground" htmlFor={fieldId}>
          Ton message
        </label>
        <textarea
          className="min-h-28 rounded border border-border bg-background p-3 text-sm text-foreground outline-none transition-colors focus:border-accent focus:ring-2 focus:ring-accent/40"
          id={fieldId}
          maxLength={MAX_LENGTH}
          onChange={(event) => setBody(event.target.value)}
          required
          value={body}
        />
        <button
          className="inline-flex min-h-11 items-center justify-center justify-self-start rounded bg-accent px-5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
          disabled={sending}
          type="submit"
        >
          {sending ? "Envoi..." : "Envoyer"}
        </button>
      </form>
    </section>
  );
}
