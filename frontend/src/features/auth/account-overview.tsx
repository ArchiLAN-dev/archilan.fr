"use client";

import { type LucideIcon, Activity, CalendarCheck, ChevronRight, Star, Users } from "lucide-react";
import Link from "next/link";
import { useEffect, useState } from "react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { fetchFriends } from "@/features/community/community-friends-api";
import { getAccountMembership, type AccountMembership } from "@/features/payments/membership-api";

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
  const [membership, setMembership] = useState<AccountMembership | null>(null);
  const [registrations, setRegistrations] = useState<number | null>(null);
  const [pendingFriends, setPendingFriends] = useState<number | null>(null);
  const [friends, setFriends] = useState<number | null>(null);

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      const [m, f] = await Promise.all([getAccountMembership(), fetchFriends()]);
      if (cancelled) return;
      setMembership(m);
      if (f) {
        setPendingFriends(f.incoming.length);
        setFriends(f.friends.length);
      }
      try {
        const res = await apiFetch(`${env.apiBaseUrl}/account/registrations`);
        const json = (await res.json()) as { data?: unknown };
        if (!cancelled && Array.isArray(json.data)) setRegistrations(json.data.length);
      } catch {
        /* non-critical */
      }
    })();
    return () => { cancelled = true; };
  }, []);

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
