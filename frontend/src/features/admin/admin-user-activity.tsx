"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { CalendarX2, KeyRound, Loader2, ShieldPlus, Trash2, UserCog, Wrench } from "lucide-react";
import type { LucideIcon } from "lucide-react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchAdminUserActivity, type AdminUserActivityEntry } from "./admin-users-api";

const ROLE_LABELS: Record<string, string> = {
  user: "utilisateur",
  member: "membre",
  admin: "administrateur",
};

/**
 * The account's audit timeline (story 36.5): the five trails the site had been writing for months
 * without ever reading them.
 *
 * Hidden when empty - an account with no recorded history does not need a panel saying so.
 */
export function AdminUserActivity({ userId }: { userId: string }) {
  const { data, isPending } = useQuery({
    queryKey: ["admin-user-activity", userId],
    queryFn: () => fetchAdminUserActivity(userId),
    staleTime: DEFAULT_STALE_TIME,
  });

  if (isPending) {
    return (
      <Panel>
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement du journal…
        </p>
      </Panel>
    );
  }

  // A failed load says so inside its own panel; it must not take the rest of the sheet down with it.
  if (data === null || data === undefined) {
    return (
      <Panel>
        <p className="text-sm text-muted-foreground">Impossible de charger le journal d&apos;activité.</p>
      </Panel>
    );
  }

  // The heading lives inside the panel precisely so an empty history hides it too.
  if (data.length === 0) return null;

  return (
    <Panel>
      <ol className="grid gap-2" role="list">
        {data.map((entry, index) => (
          <TimelineRow entry={entry} key={`${entry.type}-${entry.occurredAt}-${index}`} />
        ))}
      </ol>
    </Panel>
  );
}

function Panel({ children }: { children: React.ReactNode }) {
  return (
    <section className="grid gap-4">
      <h2 className="font-heading text-xl font-semibold text-foreground">Journal d&apos;activité</h2>
      {children}
    </section>
  );
}

function TimelineRow({ entry }: { entry: AdminUserActivityEntry }) {
  const { Icon, tone } = visualFor(entry);

  return (
    <li className="flex items-start gap-3 rounded-lg border border-border bg-surface px-4 py-3">
      <span
        aria-hidden
        className={`mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full ${tone}`}
      >
        <Icon className="size-3.5" />
      </span>
      <div className="min-w-0 flex-1">
        <p className="text-sm text-foreground">
          <EntryText entry={entry} />
        </p>
        <time className="mt-1 block text-xs text-muted-foreground" dateTime={entry.occurredAt}>
          {formatDate(entry.occurredAt)}
        </time>
      </div>
    </li>
  );
}

function EntryText({ entry }: { entry: AdminUserActivityEntry }) {
  const counterpart = <Counterpart entry={entry} />;
  const from = roleLabel(entry.previousRole);
  const to = roleLabel(entry.newRole);

  switch (entry.type) {
    case "role_changed":
      return (
        <>
          Passé de <strong>{from}</strong> à <strong>{to}</strong> par {counterpart}
        </>
      );
    case "role_change_performed":
      return (
        <>
          A fait passer {counterpart} de <strong>{from}</strong> à <strong>{to}</strong>
        </>
      );
    case "admin_account_created":
      return <>Compte administrateur créé par {counterpart}</>;
    case "admin_account_created_by":
      return <>A créé le compte administrateur de {counterpart}</>;
    case "account_deleted":
      return (
        <>
          Compte supprimé
          {entry.subject !== null ? <span className="text-muted-foreground"> · {entry.subject}</span> : null}
        </>
      );
    case "run_admin_action":
      // Not a link: a running session has no public page, and a finished one only has a recap when it
      // was built. The label names what the session belonged to.
      return (
        <>
          Action <strong>{entry.newRole ?? "inconnue"}</strong> sur une partie
          {entry.subject !== null ? <span className="text-muted-foreground"> · {entry.subject}</span> : null}
        </>
      );
    case "private_event_access":
      return (
        <>
          Accès à l&apos;événement privé{" "}
          {entry.subjectId !== null ? (
            <Link className="text-accent-text hover:underline" href={`/evenements/${entry.subjectId}`}>
              {entry.subject ?? "supprimé"}
            </Link>
          ) : (
            <span className="text-muted-foreground">{entry.subject ?? "supprimé"}</span>
          )}{" "}
          <strong className={entry.granted === true ? "text-success" : "text-danger"}>
            {entry.granted === true ? "accordé" : "refusé"}
          </strong>
        </>
      );
    default:
      return <span className="text-muted-foreground">Entrée inconnue ({entry.type})</span>;
  }
}

/** An audit outlives the account it names, so an unresolved counterpart is stated, not hidden. */
function Counterpart({ entry }: { entry: AdminUserActivityEntry }) {
  if (entry.counterpartId === null) return <span className="text-muted-foreground">le système</span>;

  if (entry.counterpartName === null) {
    return <span className="text-muted-foreground">un compte supprimé</span>;
  }

  return (
    <Link className="text-accent-text hover:underline" href={`/admin/utilisateurs/${entry.counterpartId}`}>
      {entry.counterpartName}
    </Link>
  );
}

function visualFor(entry: AdminUserActivityEntry): { Icon: LucideIcon; tone: string } {
  switch (entry.type) {
    case "role_changed":
    case "role_change_performed":
      return { Icon: UserCog, tone: "bg-accent/15 text-accent-text" };
    case "admin_account_created":
    case "admin_account_created_by":
      return { Icon: ShieldPlus, tone: "bg-accent/15 text-accent-text" };
    case "account_deleted":
      return { Icon: Trash2, tone: "bg-danger/15 text-danger" };
    case "run_admin_action":
      return { Icon: Wrench, tone: "bg-accent/15 text-accent-text" };
    case "private_event_access":
      return entry.granted === true
        ? { Icon: KeyRound, tone: "bg-success/15 text-success" }
        : { Icon: CalendarX2, tone: "bg-danger/15 text-danger" };
    default:
      return { Icon: Wrench, tone: "bg-accent/15 text-accent-text" };
  }
}

function roleLabel(role: string | null): string {
  if (role === null) return "inconnu";

  return ROLE_LABELS[role] ?? role;
}

function formatDate(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "-";

  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "long", timeStyle: "short" }).format(date);
}
