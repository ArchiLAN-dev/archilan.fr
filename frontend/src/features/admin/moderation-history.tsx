import { AlertTriangle, Ban, ShieldCheck } from "lucide-react";

import type { AdminModerationAction, AdminModerationCaseMessage, AdminUserModeration } from "./admin-users-api";

/**
 * What the staff reads about a member's moderation: state, case messages and history. Shared by the admin
 * sheet and the history panel of the reports tab (story 39.11), as flat lists rather than a card per line.
 */

const ACTION_LABELS: Record<string, string> = {
  warn: "Avertissement",
  suspend: "Suspension",
  ban: "Bannissement",
  lift: "Levée de sanction",
  note: "Note interne",
};

const DM_LABELS: Record<string, string> = {
  sent: "MP Discord envoyé",
  failed: "MP Discord impossible (MP fermés ou serveur quitté)",
  not_linked: "compte Discord non lié, pas de MP",
  unavailable: "synchronisation Discord désactivée, pas de MP",
  superseded: "MP non envoyé : sanction déjà levée",
};

const SERVER_LABELS: Record<string, string> = {
  banned: "banni du serveur Discord",
  lifted: "sanction levée sur le serveur Discord",
  timed_out: "exclu temporairement du serveur Discord",
  not_member: "pas sur le serveur Discord",
  not_linked: "compte Discord non lié",
  unavailable: "synchronisation Discord désactivée",
  superseded: "non appliquée sur Discord : sanction déjà levée",
  failed: "échec sur le serveur Discord (permission ou rôle du bot)",
};

export function ModerationStateBanner({ moderation }: { moderation: AdminUserModeration }) {
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

/** Stories 39.4 et 39.5 : ce que Discord a fait de la sanction. */
function discordLine(action: AdminModerationAction): string | null {
  const parts = [
    action.discordDm !== null ? (DM_LABELS[action.discordDm] ?? action.discordDm) : null,
    action.discordServer !== null ? (SERVER_LABELS[action.discordServer] ?? action.discordServer) : null,
  ].filter((part): part is string => part !== null);
  return parts.length > 0 ? `Discord : ${parts.join(" · ")}` : null;
}

export function ModerationActionList({ actions }: { actions: AdminModerationAction[] }) {
  if (actions.length === 0) {
    return <p className="text-sm text-muted-foreground">Aucune sanction enregistrée pour ce compte.</p>;
  }

  return (
    <ul className="divide-y divide-border" role="list">
      {actions.map((action) => {
        const discord = discordLine(action);
        return (
          <li className="grid gap-1 py-3 first:pt-0 last:pb-0" key={action.id}>
            <p className="flex flex-wrap items-baseline justify-between gap-x-3 text-sm font-semibold text-foreground">
              <span>
                {ACTION_LABELS[action.action] ?? action.action}
                <span className="ml-2 text-xs font-normal text-muted-foreground">par {action.actorName ?? "un compte supprimé"}</span>
              </span>
              <time className="text-xs font-normal text-muted-foreground" dateTime={action.createdAt}>
                {formatDate(action.createdAt)}
              </time>
            </p>
            <p className="whitespace-pre-line text-sm text-muted-foreground">{action.reason}</p>
            {action.action === "note" ? (
              <p className="text-xs text-muted-foreground">Note interne : le membre n&apos;est pas prévenu.</p>
            ) : discord !== null ? (
              <p className="text-xs text-muted-foreground">{discord}</p>
            ) : null}
          </li>
        );
      })}
    </ul>
  );
}

/** Story 39.2 : l'échange du dossier dans l'ordre ; story 39.3 : avec les réponses du staff et l'issue de leur MP. */
export function ModerationCaseMessages({ messages }: { messages: AdminModerationCaseMessage[] }) {
  return (
    <ul className="divide-y divide-border" role="list">
      {messages.map((message) => (
        <li className="grid gap-1 py-3 first:pt-0 last:pb-0" key={message.id}>
          <p className="text-xs text-muted-foreground">
            <span className={message.author === "member" ? "font-semibold text-foreground" : "font-semibold text-accent-text"}>
              {message.author === "member" ? "Membre" : "Staff"}
            </span>
            {message.authorName !== null ? ` · ${message.authorName}` : ""} · {formatDate(message.createdAt)}
            {message.source === "discord_dm" ? " · en MP au bot" : ""}
            {message.author === "staff" ? ` · ${message.discordDm !== null ? (DM_LABELS[message.discordDm] ?? message.discordDm) : "MP Discord en cours"}` : ""}
          </p>
          <p className="whitespace-pre-line text-sm text-foreground">{message.body}</p>
        </li>
      ))}
    </ul>
  );
}

function formatDate(iso: string | null): string {
  if (iso === null) return "-";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "-";

  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "long", timeStyle: "short" }).format(date);
}
