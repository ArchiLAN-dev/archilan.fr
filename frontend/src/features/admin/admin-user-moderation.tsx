"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { AlertTriangle, Ban, Gavel, Loader2, ShieldCheck } from "lucide-react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  applyModerationAction,
  fetchAdminUserModeration,
  replyToMember,
  type AdminModerationAction,
  type AdminModerationCaseMessage,
  type AdminUserModeration,
  type ModerationCommand,
} from "./admin-users-api";

const ACTION_LABELS: Record<string, string> = {
  warn: "Avertissement",
  suspend: "Suspension",
  ban: "Bannissement",
  lift: "Levée de sanction",
};

type Props = {
  userId: string;
  /** An admin account cannot be moderated - the server refuses it, so the UI says so up front. */
  isAdmin: boolean;
  isSelf: boolean;
};

/**
 * Moderation panel of the admin user sheet (story 36.2). Everything it drives already existed as
 * endpoints since story 30.29; it was simply invisible from a person's sheet.
 */
export function AdminUserModeration({ userId, isAdmin, isSelf }: Props) {
  const queryClient = useQueryClient();
  const queryKey = ["admin-user-moderation", userId];

  const { data, isPending } = useQuery({
    queryKey,
    queryFn: () => fetchAdminUserModeration(userId),
    staleTime: DEFAULT_STALE_TIME,
  });

  if (isPending) {
    return (
      <Panel>
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement de la modération…
        </p>
      </Panel>
    );
  }

  if (data === null || data === undefined) {
    return (
      <Panel>
        <p className="text-sm text-muted-foreground">Impossible de charger la modération de ce compte.</p>
      </Panel>
    );
  }

  return (
    <Panel>
      <StateBanner moderation={data} />

      {isAdmin || isSelf ? (
        <p className="rounded-lg border border-border bg-surface px-4 py-3 text-sm text-muted-foreground">
          {isSelf
            ? "Tu ne peux pas te modérer toi-même."
            : "Un compte administrateur ne peut pas être modéré. Retire-lui d'abord ses droits."}
        </p>
      ) : (
        <ActionForm
          onDone={async () => {
            await queryClient.invalidateQueries({ queryKey });
          }}
          userId={userId}
        />
      )}

      {data.moderationCase !== null && data.moderationCase.messages.length > 0 ? (
        <CaseMessages messages={data.moderationCase.messages} />
      ) : null}

      {!isAdmin && !isSelf && (data.moderationCase !== null || data.actions.length > 0) ? (
        <ReplyForm
          onDone={async () => {
            await queryClient.invalidateQueries({ queryKey });
          }}
          userId={userId}
        />
      ) : null}

      <div className="grid gap-2">
        <h3 className="text-sm font-semibold text-foreground">Historique</h3>
        {data.actions.length === 0 ? (
          <p className="rounded-lg border border-border bg-surface px-4 py-6 text-center text-sm text-muted-foreground">
            Aucune sanction enregistrée pour ce compte.
          </p>
        ) : (
          <ul className="grid gap-2" role="list">
            {data.actions.map((action) => (
              <ActionRow action={action} key={action.id} />
            ))}
          </ul>
        )}
      </div>
    </Panel>
  );
}

function StateBanner({ moderation }: { moderation: AdminUserModeration }) {
  const { state, unresolvedReportCount, severityScore } = moderation;
  const banned = state.bannedAt !== null;
  const suspended = !banned && state.suspendedUntil !== null;

  const tone = banned
    ? "border-danger/50 bg-danger/10 text-danger"
    : suspended
      ? "border-accent-warm/50 bg-accent-warm/10 text-accent-warm"
      : "border-border bg-surface text-success";

  const Icon = banned ? Ban : suspended ? AlertTriangle : ShieldCheck;

  return (
    <div className={`grid gap-2 rounded-lg border px-4 py-3 ${tone}`}>
      <p className="flex items-center gap-2 text-sm font-semibold">
        <Icon aria-hidden className="size-4" />
        {banned
          ? `Banni depuis le ${formatDate(state.bannedAt)}`
          : suspended
            ? `Suspendu jusqu'au ${formatDate(state.suspendedUntil)}`
            : "Compte sain"}
      </p>
      {state.reason !== null ? <p className="text-sm">Motif : {state.reason}</p> : null}
      <p className="text-xs text-muted-foreground">
        {unresolvedReportCount === 0
          ? "Aucun signalement de profil non résolu."
          : `${unresolvedReportCount} signalement${unresolvedReportCount > 1 ? "s" : ""} non résolu${unresolvedReportCount > 1 ? "s" : ""} · gravité ${severityScore}`}
      </p>
      {moderation.moderationCase !== null ? (
        <p className="text-xs text-muted-foreground">
          Dossier de modération {moderation.moderationCase.status === "open" ? "ouvert" : "clos"}
          {moderation.moderationCase.forumThreadUrl !== null ? (
            <>
              {" · "}
              <a
                className="font-semibold text-accent-text underline-offset-2 hover:underline"
                href={moderation.moderationCase.forumThreadUrl}
                rel="noreferrer"
                target="_blank"
              >
                voir le post sur Discord
              </a>
            </>
          ) : null}
        </p>
      ) : null}
    </div>
  );
}

const DM_LABELS: Record<string, string> = {
  sent: "MP Discord envoyé",
  failed: "MP Discord impossible (MP fermés ou serveur quitté)",
  not_linked: "compte Discord non lié, pas de MP",
  unavailable: "bot non configuré, pas de MP",
};

/** Story 39.2 : l'échange du dossier dans l'ordre ; story 39.3 : avec les réponses du staff et l'issue de leur MP. */
function CaseMessages({ messages }: { messages: AdminModerationCaseMessage[] }) {
  return (
    <div className="grid gap-2">
      <h3 className="text-sm font-semibold text-foreground">Messages du dossier</h3>
      <ul className="grid gap-2" role="list">
        {messages.map((message) => (
          <li className="grid gap-1 rounded-lg border border-border bg-surface px-4 py-3" key={message.id}>
            <p className="text-xs text-muted-foreground">
              {message.author === "member" ? "Membre" : "Staff"}
              {message.authorName !== null ? ` · ${message.authorName}` : ""} · {formatDate(message.createdAt)}
              {message.author === "staff" ? ` · ${message.discordDm !== null ? (DM_LABELS[message.discordDm] ?? message.discordDm) : "MP Discord en cours"}` : ""}
            </p>
            <p className="whitespace-pre-line text-sm text-foreground">{message.body}</p>
          </li>
        ))}
      </ul>
    </div>
  );
}

/**
 * Story 39.3 : la réponse part du site, vers le membre (notification, fil, MP du bot si son compte est lié) et
 * dans le forum staff. Ce qui s'écrit dans le post du forum, lui, reste entre membres du staff.
 */
function ReplyForm({ userId, onDone }: { userId: string; onDone: () => Promise<void> }) {
  const [body, setBody] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    setPending(true);
    setError(null);
    const failure = await replyToMember(userId, body);
    setPending(false);
    if (failure !== null) {
      setError(failure);
      return;
    }
    setBody("");
    await onDone();
  }

  return (
    <form className="grid gap-2 rounded-lg border border-border bg-surface px-4 py-3" onSubmit={submit}>
      <label className="text-sm font-semibold text-foreground" htmlFor={`reply-${userId}`}>
        Répondre au membre
      </label>
      <p className="text-xs text-muted-foreground">
        Envoyé au membre sur le site et en MP Discord si son compte est lié, et posté dans le forum staff.
      </p>
      <textarea
        className="min-h-24 rounded border border-border bg-background p-3 text-sm text-foreground outline-none transition-colors focus:border-accent focus:ring-2 focus:ring-accent/40"
        id={`reply-${userId}`}
        maxLength={2000}
        onChange={(event) => setBody(event.target.value)}
        required
        value={body}
      />
      {error !== null ? (
        <p className="text-sm text-danger" role="alert">
          {error}
        </p>
      ) : null}
      <button
        className="inline-flex min-h-10 items-center justify-center justify-self-start rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
        disabled={pending || body.trim() === ""}
        type="submit"
      >
        {pending ? "Envoi..." : "Envoyer la réponse"}
      </button>
    </form>
  );
}

function ActionForm({ userId, onDone }: { userId: string; onDone: () => Promise<void> }) {
  const [command, setCommand] = useState<ModerationCommand>("warn");
  const [reason, setReason] = useState("");
  const [until, setUntil] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    setPending(true);
    setError(null);

    const message = await applyModerationAction(
      userId,
      command,
      reason,
      command === "suspend" && until !== "" ? new Date(until).toISOString() : undefined,
    );

    if (message === null) {
      setReason("");
      setUntil("");
      await onDone();
    } else {
      setError(message);
    }
    setPending(false);
  }

  return (
    <form className="grid gap-3 rounded-lg border border-border bg-surface px-4 py-3" onSubmit={submit}>
      <div className="flex flex-wrap items-center gap-3">
        <label className="text-sm text-muted-foreground" htmlFor="moderation-action">
          Action :
        </label>
        <select
          className="min-h-9 rounded-lg border border-border bg-background px-3 text-sm text-foreground focus:border-accent focus:outline-none"
          id="moderation-action"
          onChange={(e) => setCommand(toCommand(e.target.value))}
          value={command}
        >
          <option value="warn">Avertir</option>
          <option value="suspend">Suspendre</option>
          <option value="ban">Bannir</option>
          <option value="lift">Lever la sanction</option>
        </select>

        {command === "suspend" ? (
          <>
            <label className="text-sm text-muted-foreground" htmlFor="moderation-until">
              Jusqu&apos;au :
            </label>
            <input
              className="min-h-9 rounded-lg border border-border bg-background px-3 text-sm text-foreground focus:border-accent focus:outline-none"
              id="moderation-until"
              onChange={(e) => setUntil(e.target.value)}
              required
              type="datetime-local"
              value={until}
            />
          </>
        ) : null}
      </div>

      <label className="grid gap-1">
        <span className="text-sm text-muted-foreground">Motif (obligatoire)</span>
        <input
          className="min-h-10 rounded-lg border border-border bg-background px-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-accent focus:outline-none"
          onChange={(e) => setReason(e.target.value)}
          placeholder="Ce motif est conservé dans l'historique"
          required
          value={reason}
        />
      </label>

      <div className="flex items-center gap-3">
        <button
          className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:opacity-40"
          disabled={pending || reason.trim() === ""}
          type="submit"
        >
          {pending ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <Gavel aria-hidden className="size-4" />}
          Appliquer
        </button>
        {error !== null ? <p className="text-sm text-danger">{error}</p> : null}
      </div>
    </form>
  );
}

function ActionRow({ action }: { action: AdminModerationAction }) {
  return (
    <li className="grid gap-1 rounded-lg border border-border bg-surface px-4 py-3">
      <p className="text-sm font-semibold text-foreground">
        {ACTION_LABELS[action.action] ?? action.action}
        <span className="ml-2 text-xs font-normal text-muted-foreground">
          par {action.actorName ?? "un compte supprimé"}
        </span>
      </p>
      <p className="text-sm text-muted-foreground">{action.reason}</p>
      <time className="text-xs text-muted-foreground" dateTime={action.createdAt}>
        {formatDate(action.createdAt)}
      </time>
    </li>
  );
}

function Panel({ children }: { children: React.ReactNode }) {
  return (
    <section className="grid gap-4">
      <h2 className="font-heading text-xl font-semibold text-foreground">Modération</h2>
      {children}
    </section>
  );
}

function toCommand(value: string): ModerationCommand {
  return value === "suspend" || value === "ban" || value === "lift" ? value : "warn";
}

function formatDate(iso: string | null): string {
  if (iso === null) return "-";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "-";

  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "long", timeStyle: "short" }).format(date);
}
