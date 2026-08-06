"use client";

import Link from "next/link";
import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, Loader2, ShieldCheck, ShieldOff } from "lucide-react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";

import {
  fetchAdminUserDetail,
  updateAdminUserRole,
  type AdminUserDetail,
  type AssignableRole,
} from "./admin-users-api";
import { useAuth } from "@/features/auth/auth-context";

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
      <p className="flex items-center gap-2 text-sm text-muted-foreground">
        <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement de la fiche…
      </p>
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
      <div className="grid gap-4">
        <BackLink />
        <p className="rounded-lg border border-border bg-surface px-4 py-8 text-center text-sm text-muted-foreground">
          {message}
        </p>
      </div>
    );
  }

  const user = data.user;
  const isSelf = viewer?.id === user.id;

  return (
    <div className="grid gap-8">
      <div className="grid gap-4">
        <BackLink />
        <header className="flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0">
            <h1 className="font-heading text-3xl font-bold text-foreground">
              {user.displayName ?? user.email}
            </h1>
            <p className="mt-1 truncate text-sm text-muted-foreground">{user.email}</p>
          </div>
          <div className="flex shrink-0 flex-wrap items-center gap-2">
            <Badge tone={user.role === "admin" ? "accent" : "muted"}>{ROLE_LABELS[user.role]}</Badge>
            <Badge tone={user.status === "deleted" ? "danger" : "success"}>
              {user.status === "deleted" ? "Supprimé" : "Actif"}
            </Badge>
            {user.emailVerified ? null : <Badge tone="warning">Email non vérifié</Badge>}
          </div>
        </header>
      </div>

      <Section title="Identité">
        <dl className="grid gap-4 sm:grid-cols-2">
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
      </Section>

      <Section title="Rôles">
        <RolePanel isSelf={isSelf} onChanged={reload} user={user} />
      </Section>
    </div>
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
        <p className="rounded-lg border border-border bg-surface px-4 py-3 text-sm text-muted-foreground">
          {blocked}
        </p>
      ) : null}

      <ul className="grid gap-3" role="list">
        {(["user", "member", "admin"] as const).map((role) => {
          const current = user.role === role;
          return (
            <li
              className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-surface px-4 py-3"
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

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="grid gap-4">
      <h2 className="font-heading text-xl font-semibold text-foreground">{title}</h2>
      {children}
    </section>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="rounded-lg border border-border bg-surface px-4 py-3">
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="mt-1 text-sm text-foreground">{children}</dd>
    </div>
  );
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
