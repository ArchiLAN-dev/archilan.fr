"use client";

import Link from "next/link";
import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, IdCard, KeyRound, Loader2, ShieldCheck, ShieldOff } from "lucide-react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";

import {
  fetchAdminUserDetail,
  updateAdminUserRole,
  type AdminUserDetail,
  type AssignableRole,
} from "./admin-users-api";
import { useAuth } from "@/features/auth/auth-context";
import { AdminUserActivity } from "./admin-user-activity";
import { AdminUserParticipation } from "./admin-user-participation";
import { AdminUserModeration } from "./admin-user-moderation";
import { AdminUserGaming } from "./admin-user-gaming";
import { AdminUserActions } from "./admin-user-actions";
import { SHEET_LIST_CLASS, SheetNav, SheetSection } from "./admin-sheet-section";

const ROLE_LABELS: Record<AssignableRole, string> = {
  user: "Utilisateur",
  member: "Membre",
  admin: "Administrateur",
};

const ROLE_HELP: Record<AssignableRole, string> = {
  user: "Compte simple, sans adhésion enregistrée.",
  member: "Statut d'adhérent. N'ouvre aucun droit d'administration.",
  admin: "Accès complet au backoffice.",
};

type Props = { userId: string };

/**
 * The admin shell's `<main>` carries no gutter: every route brings its own. Story 36.7: 16 px read as
 * glued to the sidebar and nothing capped the width, so the gutter now grows with the screen and the
 * sheet stops at the content width like the other content pages.
 */
function Shell({ children }: { children: React.ReactNode }) {
  return <div className="mx-auto grid w-full min-w-0 max-w-content grid-cols-1 gap-8 px-4 py-10 md:px-8 lg:px-10">{children}</div>;
}

/**
 * A user's admin sheet (story 36.1). Composed of autonomous sections so the epic's remaining panels
 * (moderation, adhesion, jeu, journal) can be added without touching this file's existing ones - the
 * lesson story 30.36 drew from the AccountTabs monolith.
 */
export function AdminUserDetailPage({ userId }: Props) {
  const { user: viewer } = useAuth();
  const queryClient = useQueryClient();
  const queryKey = ["admin-user-detail", userId];

  const { data, isPending } = useQuery({
    queryKey,
    queryFn: () => fetchAdminUserDetail(userId),
    staleTime: DEFAULT_STALE_TIME,
  });

  async function reload(): Promise<void> {
    await queryClient.invalidateQueries({ queryKey });
  }

  if (isPending) {
    return (
      <Shell>
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement de la fiche…
        </p>
      </Shell>
    );
  }

  if (data === undefined || data.kind !== "ready") {
    const message =
      data === undefined
        ? "Impossible de charger cette fiche utilisateur."
        : data.kind === "notFound"
          ? "Cet utilisateur n'existe pas."
          : data.message;

    return (
      <Shell>
        <div className="grid gap-4">
          <BackLink />
          <p className="rounded-lg border border-border bg-surface px-4 py-8 text-center text-sm text-muted-foreground">
            {message}
          </p>
        </div>
      </Shell>
    );
  }

  const user = data.user;
  const isSelf = viewer?.id === user.id;

  return (
    <Shell>
      <div className="grid gap-5">
        <BackLink />
        <header className="flex flex-wrap items-center gap-4">
          <span
            aria-hidden
            className="flex size-14 shrink-0 items-center justify-center rounded-full bg-accent/20 font-heading text-xl font-bold text-accent-text"
          >
            {initials(user.displayName ?? user.email)}
          </span>
          {/* The 14rem basis makes the badges wrap below on a phone instead of truncating the name. */}
          <div className="min-w-0 flex-1 basis-56">
            <h1 className="truncate font-heading text-2xl font-bold text-foreground md:text-3xl">
              {user.displayName ?? user.email}
            </h1>
            <p className="mt-0.5 truncate text-sm text-muted-foreground">{user.email}</p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={user.role === "admin" ? "accent" : "muted"}>{ROLE_LABELS[user.role]}</Badge>
            <Badge tone={user.status === "deleted" ? "danger" : "success"}>
              {user.status === "deleted" ? "Supprimé" : "Actif"}
            </Badge>
            {user.emailVerified ? null : <Badge tone="warning">Email non vérifié</Badge>}
          </div>
        </header>
        <SheetNav />
      </div>

      <SheetSection description="Qui est ce compte, et depuis quand." icon={IdCard} id="identite" title="Identité">
        <dl className="grid gap-x-8 gap-y-4 rounded-lg border border-border bg-surface p-5 sm:grid-cols-2">
          <Field label="Pseudo public">
            {user.slug === null ? (
              <span className="text-muted-foreground">Aucun profil public</span>
            ) : (
              <Link className="text-accent-text hover:underline" href={`/joueurs/${user.slug}`}>
                /joueurs/{user.slug}
              </Link>
            )}
          </Field>
          <Field label="Email vérifié">{user.emailVerified ? "Oui" : "Non"}</Field>
          <Field label="Inscrit le">{formatDate(user.createdAt)}</Field>
          <Field label="Dernière modification">{formatDate(user.updatedAt)}</Field>
          {user.deletedAt !== null ? (
            <Field label="Supprimé le">{formatDate(user.deletedAt)}</Field>
          ) : null}
          <Field label="Rôles techniques">
            <span className="font-mono text-xs text-muted-foreground">{user.roles.join(", ")}</span>
          </Field>
        </dl>
      </SheetSection>

      <SheetSection
        description="Sessions, vérification de l'email et niveau d'accès au site."
        icon={KeyRound}
        id="acces"
        title="Accès et rôles"
      >
        <AdminUserActions emailVerified={user.emailVerified} isSelf={isSelf} userId={user.id} />
        <RolePanel isSelf={isSelf} onChanged={reload} user={user} />
      </SheetSection>

      <AdminUserModeration isAdmin={user.role === "admin"} isSelf={isSelf} name={user.displayName ?? user.email} userId={user.id} />

      <AdminUserParticipation userId={user.id} />

      <AdminUserGaming userId={user.id} />

      <AdminUserActivity userId={user.id} />
    </Shell>
  );
}

function RolePanel({
  user,
  isSelf,
  onChanged,
}: {
  user: AdminUserDetail;
  isSelf: boolean;
  onChanged: () => Promise<void>;
}) {
  const [pending, setPending] = useState<AssignableRole | null>(null);
  const [error, setError] = useState<string | null>(null);

  // Why a transition is impossible is stated up front rather than left to a server rejection.
  const blocked = isSelf
    ? "Tu ne peux pas modifier ton propre rôle. Demande à un autre administrateur."
    : user.status === "deleted"
      ? "Ce compte est supprimé : ses rôles ne sont plus modifiables."
      : null;

  async function assign(role: AssignableRole): Promise<void> {
    setPending(role);
    setError(null);
    const updated = await updateAdminUserRole(user.id, role);
    if (updated === null) {
      setError("Le changement de rôle a été refusé. Recharge la fiche et réessaie.");
    } else {
      await onChanged();
    }
    setPending(null);
  }

  return (
    <div className="grid gap-4">
      {blocked !== null ? (
        <p className="text-sm text-muted-foreground">{blocked}</p>
      ) : null}

      <ul className={SHEET_LIST_CLASS} role="list">
        {(["user", "member", "admin"] as const).map((role) => {
          const current = user.role === role;
          return (
            <li
              className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
              key={role}
            >
              <div className="min-w-0">
                <p className="flex items-center gap-2 text-sm font-semibold text-foreground">
                  {role === "admin" ? (
                    current ? (
                      <ShieldCheck aria-hidden className="size-4 text-accent-text" />
                    ) : (
                      <ShieldOff aria-hidden className="size-4 text-muted-foreground" />
                    )
                  ) : null}
                  {ROLE_LABELS[role]}
                  {current ? <span className="text-xs font-normal text-accent-text">(actuel)</span> : null}
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">{ROLE_HELP[role]}</p>
              </div>
              <button
                className="inline-flex min-h-9 shrink-0 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-40"
                disabled={current || blocked !== null || pending !== null}
                onClick={() => void assign(role)}
                type="button"
              >
                {pending === role ? <Loader2 aria-hidden className="size-4 animate-spin" /> : null}
                {current ? "Rôle actuel" : `Passer ${ROLE_LABELS[role].toLowerCase()}`}
              </button>
            </li>
          );
        })}
      </ul>

      {error !== null ? <p className="text-sm text-danger">{error}</p> : null}
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="mt-1 break-words text-sm text-foreground">{children}</dd>
    </div>
  );
}

function initials(name: string): string {
  const letters = name
    .split(/[\s._@-]+/)
    .filter((part) => part !== "")
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("");
  return letters === "" ? "?" : letters;
}

function Badge({ children, tone }: { children: React.ReactNode; tone: "accent" | "muted" | "success" | "danger" | "warning" }) {
  const tones: Record<typeof tone, string> = {
    accent: "border-accent bg-accent/10 text-accent-text",
    muted: "border-border text-muted-foreground",
    success: "border-border text-success",
    danger: "border-border text-danger",
    warning: "border-border text-accent-warm",
  };

  return (
    <span className={`inline-flex items-center rounded border px-2 py-1 text-xs font-semibold ${tones[tone]}`}>
      {children}
    </span>
  );
}

function BackLink() {
  return (
    <Link
      className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
      href="/admin/utilisateurs"
    >
      <ArrowLeft aria-hidden className="size-3.5" />
      Retour à l&apos;annuaire
    </Link>
  );
}

function formatDate(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "-";

  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "long", timeStyle: "short" }).format(date);
}
