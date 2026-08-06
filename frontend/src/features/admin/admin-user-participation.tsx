"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { Loader2 } from "lucide-react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  fetchAdminUserParticipation,
  type AdminUserMembership,
  type AdminUserRegistration,
} from "./admin-users-api";

const MEMBERSHIP_STATUS: Record<string, { label: string; tone: string }> = {
  active: { label: "Active", tone: "text-success" },
  expired: { label: "Expirée", tone: "text-muted-foreground" },
  cancelled: { label: "Annulée", tone: "text-danger" },
};

const SOURCE_LABELS: Record<string, string> = {
  helloasso: "HelloAsso",
  admin: "Saisie admin",
  dolibarr: "Dolibarr",
};

const REGISTRATION_STATUS: Record<string, string> = {
  reserved: "Réservée",
  confirmed: "Confirmée",
  cancelled: "Annulée",
  waitlisted: "Liste d'attente",
};

/**
 * Memberships and event registrations of one member (story 36.3).
 *
 * Two panels rather than one: they answer different questions ("is he up to date?" and "what did he
 * sign up for?"), and each disappears on its own when it has nothing to show.
 */
export function AdminUserParticipation({ userId }: { userId: string }) {
  const { data, isPending } = useQuery({
    queryKey: ["admin-user-participation", userId],
    queryFn: () => fetchAdminUserParticipation(userId),
    staleTime: DEFAULT_STALE_TIME,
  });

  if (isPending) {
    return (
      <section className="grid gap-4">
        <h2 className="font-heading text-xl font-semibold text-foreground">Adhésion et inscriptions</h2>
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement…
        </p>
      </section>
    );
  }

  if (data === null || data === undefined) {
    return (
      <section className="grid gap-4">
        <h2 className="font-heading text-xl font-semibold text-foreground">Adhésion et inscriptions</h2>
        <p className="text-sm text-muted-foreground">
          Impossible de charger l&apos;adhésion et les inscriptions.
        </p>
      </section>
    );
  }

  // Both panels stay visible when empty. This is an admin tool, not the public hub: "this person has
  // never been a member" is an answer, and a vanished panel is indistinguishable from one that failed
  // to render.
  return (
    <>
      <section className="grid gap-4">
        <h2 className="font-heading text-xl font-semibold text-foreground">Adhésion</h2>
        {data.memberships.length === 0 ? (
          <EmptyState>Aucune adhésion enregistrée pour ce compte.</EmptyState>
        ) : (
          <ul className="grid gap-3" role="list">
            {data.memberships.map((membership) => (
              <MembershipRow key={membership.id} membership={membership} />
            ))}
          </ul>
        )}
      </section>

      <section className="grid gap-4">
        <h2 className="font-heading text-xl font-semibold text-foreground">Inscriptions</h2>
        {data.registrations.length === 0 ? (
          <EmptyState>Ce membre ne s&apos;est inscrit à aucun événement.</EmptyState>
        ) : (
          <ul className="grid gap-3" role="list">
            {data.registrations.map((registration) => (
              <RegistrationRow key={registration.registrationId} registration={registration} />
            ))}
          </ul>
        )}
      </section>
    </>
  );
}

function EmptyState({ children }: { children: React.ReactNode }) {
  return (
    <p className="rounded-lg border border-border bg-surface px-4 py-6 text-center text-sm text-muted-foreground">
      {children}
    </p>
  );
}

function MembershipRow({ membership }: { membership: AdminUserMembership }) {
  const status = MEMBERSHIP_STATUS[membership.status] ?? {
    label: membership.status,
    tone: "text-muted-foreground",
  };

  return (
    <li className="grid gap-2 rounded-lg border border-border bg-surface px-4 py-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className={`text-sm font-semibold ${status.tone}`}>{status.label}</p>
        <p className="text-xs text-muted-foreground">
          {SOURCE_LABELS[membership.source] ?? membership.source}
        </p>
      </div>
      <p className="text-sm text-foreground">
        Du {formatDate(membership.startedAt)} au {formatDate(membership.expiresAt)}
      </p>
      {membership.helloassoOrderId !== null || membership.adminNote !== null ? (
        <div className="grid gap-1 border-t border-border pt-2 text-xs text-muted-foreground">
          {membership.helloassoOrderId !== null ? (
            <p>Commande HelloAsso : {membership.helloassoOrderId}</p>
          ) : null}
          {/* Internal note: it belongs here and nowhere public. */}
          {membership.adminNote !== null ? <p>Note admin : {membership.adminNote}</p> : null}
        </div>
      ) : null}
    </li>
  );
}

function RegistrationRow({ registration }: { registration: AdminUserRegistration }) {
  return (
    <li className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-surface px-4 py-3">
      <div className="min-w-0">
        <p className="truncate text-sm font-semibold text-foreground">{registration.eventTitle}</p>
        <p className="mt-0.5 text-xs text-muted-foreground">
          {registration.eventStartDate !== null ? formatDate(registration.eventStartDate) : "Date inconnue"}
          {" · "}
          {REGISTRATION_STATUS[registration.registrationStatus] ?? registration.registrationStatus}
          {registration.slotCount > 0
            ? ` · ${registration.slotCount} ${registration.slotCount === 1 ? "slot" : "slots"}`
            : null}
        </p>
      </div>
      {/* `eventSlug` carries the event ID, not a slug - events have none yet, and the API field is
          simply misnamed upstream (DbalAccountRegistrationsQuery maps 'eventSlug' => $eventId). The
          admin route wants the id, so this is right as written; do not "fix" it. */}
      <Link
        className="shrink-0 text-sm font-semibold text-accent-text hover:underline"
        href={`/admin/evenements/${registration.eventSlug}/inscriptions`}
      >
        Voir les inscriptions
      </Link>
    </li>
  );
}

function formatDate(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "-";

  return new Intl.DateTimeFormat("fr-FR", { dateStyle: "long" }).format(date);
}
