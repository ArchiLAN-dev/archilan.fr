"use client";

import { type LucideIcon, Activity, CalendarCheck, ChevronRight, Star, Users } from "lucide-react";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchFriends } from "@/features/community/community-friends-api";
import { getAccountMembership, type AccountMembership } from "@/features/payments/membership-api";
import { fetchAccountRegistrations } from "./auth-api";

function formatDate(iso: string): string {
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? iso
    : d.toLocaleDateString("fr-FR", { day: "numeric", month: "long", year: "numeric" });
}

function membershipLine(m: AccountMembership | null): string {
  if (!m || m.status === "none") return "Tu n'es pas encore membre.";
  if (m.status === "expired") return m.expiresAt ? `Expirée le ${formatDate(m.expiresAt)}` : "Adhésion expirée.";
  return m.expiresAt ? `Membre - expire le ${formatDate(m.expiresAt)}` : "Membre actif.";
}

export function AccountOverview() {
  // Best-effort counters: every fetcher resolves to null on error (never throws), so retry stays off
  // and a failed card simply keeps its "…" placeholder like before.
  const { data: membershipData } = useQuery({
    queryKey: ["account-membership"],
    queryFn: getAccountMembership,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const { data: friendsData } = useQuery({
    queryKey: ["community-friends"],
    queryFn: fetchFriends,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const { data: registrationsData } = useQuery({
    queryKey: ["account-registrations"],
    queryFn: fetchAccountRegistrations,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  const membership: AccountMembership | null = membershipData ?? null;
  const friends = friendsData ? friendsData.friends.length : null;
  const pendingFriends = friendsData ? friendsData.incoming.length : null;
  const registrations = registrationsData ? registrationsData.length : null;

  return (
    <div className="grid gap-4">
      <p className="text-sm text-muted-foreground">Un aperçu de ton espace. Choisis une section à gauche pour entrer dans le détail.</p>
      <div className="grid gap-3 sm:grid-cols-2">
        <OverviewCard
          href="/compte/adhesion"
          icon={Star}
          line={membershipLine(membership)}
          title="Adhésion"
        />
        <OverviewCard
          href="/compte/inscriptions"
          icon={CalendarCheck}
          line={registrations === null ? "…" : `${registrations} inscription${registrations > 1 ? "s" : ""}`}
          title="Inscriptions"
        />
        <OverviewCard
          href="/compte/amis"
          icon={Users}
          line={
            friends === null
              ? "…"
              : `${friends} ami${friends > 1 ? "s" : ""}${pendingFriends ? ` - ${pendingFriends} en attente` : ""}`
          }
          title="Amis"
        />
        <OverviewCard
          href="/compte/activite"
          icon={Activity}
          line="Ton fil d'activité récent"
          title="Activité"
        />
      </div>
    </div>
  );
}

function OverviewCard({
  href,
  icon: Icon,
  line,
  title,
}: {
  href: string;
  icon: LucideIcon;
  line: string;
  title: string;
}) {
  return (
    <Link
      className="card-glow group flex items-center gap-3 rounded-xl border border-border bg-surface p-4 transition-colors hover:border-accent/40"
      href={href}
    >
      <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-accent/15 text-accent-text">
        <Icon aria-hidden className="size-5" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block font-heading text-sm font-semibold text-foreground">{title}</span>
        <span className="block truncate text-xs text-muted-foreground">{line}</span>
      </span>
      <ChevronRight aria-hidden className="size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-accent-text" />
    </Link>
  );
}
