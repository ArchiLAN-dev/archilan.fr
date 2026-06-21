"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Bot, Calendar, CreditCard, Gamepad2, Library, Newspaper, Users } from "lucide-react";
import { useAuth } from "@/features/auth/auth-context";
import { env } from "@/lib/env";

type AdminEventStatus = "draft" | "published" | "in-progress" | "completed";
type AdminEventSummary = { status: AdminEventStatus };

type DashboardStats = {
  totalActiveRegistrations: number;
  gameCount: number;
  userCount: number;
  activeMemberCount: number;
  totalRevenueCents: number;
};

const sections = [
  {
    href: "/admin/evenements",
    icon: Calendar,
    label: "Événements",
    description: "Créer, publier et gérer les événements ArchiLAN.",
    soon: false,
  },
  {
    href: "/admin/actualites",
    icon: Newspaper,
    label: "Actualités",
    description: "Rédiger et publier les articles et récaps.",
    soon: false,
  },
  {
    href: "/admin/jeux",
    icon: Gamepad2,
    label: "Jeux",
    description: "Gérer la bibliothèque de jeux Archipelago.",
    soon: false,
  },
  {
    href: "/admin/utilisateurs",
    icon: Users,
    label: "Utilisateurs",
    description: "Consulter et gérer les comptes membres.",
    soon: false,
  },
  {
    href: "/admin/adhesions",
    icon: CreditCard,
    label: "Adhésions",
    description: "Gérer les adhésions et synchroniser Dolibarr.",
    soon: false,
  },
  {
    href: "/admin/catalogue",
    icon: Library,
    label: "Catalogue",
    description: "Synchroniser le catalogue de jeux depuis Google Sheets.",
    soon: false,
  },
  {
    href: "/admin/discord",
    icon: Bot,
    label: "Discord Bot",
    description: "Statut du bot et synchronisation des rôles Discord.",
    soon: false,
  },
] as const;

const eurFormatter = new Intl.NumberFormat("fr-FR", { style: "currency", currency: "EUR", maximumFractionDigits: 0 });
const numFormatter = new Intl.NumberFormat("fr-FR");

export default function AdminDashboardPage() {
  const { user } = useAuth();
  const [publishedEvents, setPublishedEvents] = useState<number | null>(null);
  const [dashStats, setDashStats] = useState<DashboardStats | null>(null);
  const [statsLoading, setStatsLoading] = useState(true);

  // The community pseudo (auth payload's displayName already resolves the community override); the email
  // local-part is only a last-resort fallback.
  const displayName = (user?.displayName ?? "").trim() || (user ? user.email.split("@")[0] : "");

  useEffect(() => {
    Promise.all([
      fetch(`${env.apiBaseUrl}/admin/events`, { credentials: "include" })
        .then((r) => (r.ok ? r.json() : null))
        .then((payload: { data: AdminEventSummary[] } | null) => {
          if (payload?.data) {
            setPublishedEvents(payload.data.filter((e) => e.status !== "draft").length);
          }
        })
        .catch(() => {}),

      fetch(`${env.apiBaseUrl}/admin/dashboard-stats`, { credentials: "include" })
        .then((r) => (r.ok ? r.json() : null))
        .then((payload: { data: DashboardStats } | null) => {
          if (payload?.data) setDashStats(payload.data);
        })
        .catch(() => {}),
    ]).finally(() => setStatsLoading(false));
  }, []);

  return (
    <div className="w-full px-4 py-6 md:py-8">
      <header className="mb-8">
        <h1 className="font-heading text-3xl font-bold text-foreground">
          Bonjour,&nbsp;{displayName}&nbsp;!
        </h1>
        <p className="mt-1 text-muted-foreground">Que veux-tu faire aujourd&apos;hui&nbsp;?</p>
      </header>

      {/* At-a-glance stats */}
      {statsLoading ? (
        <div className="mb-8 grid grid-cols-2 gap-4 lg:grid-cols-3">
          {[0, 1, 2, 3, 4, 5].map((i) => (
            <div className="h-20 animate-pulse rounded-lg bg-surface-2" key={i} />
          ))}
        </div>
      ) : (
        <div className="mb-8 grid grid-cols-2 gap-4 lg:grid-cols-3">
          <StatCard label="Événements publiés" value={publishedEvents !== null ? numFormatter.format(publishedEvents) : null} />
          <StatCard label="Inscriptions actives" value={dashStats !== null ? numFormatter.format(dashStats.totalActiveRegistrations) : null} />
          <StatCard label="Jeux en bibliothèque" value={dashStats !== null ? numFormatter.format(dashStats.gameCount) : null} />
          <StatCard label="Comptes inscrits" value={dashStats !== null ? numFormatter.format(dashStats.userCount) : null} />
          <StatCard label="Adhésions en cours" value={dashStats !== null ? numFormatter.format(dashStats.activeMemberCount) : null} />
          <StatCard label="Recettes HelloAsso" value={dashStats !== null ? eurFormatter.format(dashStats.totalRevenueCents / 100) : null} />
        </div>
      )}

      {/* Section tiles */}
      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        {sections.map(({ href, icon: Icon, label, description, soon }) => (
          <Link
            className="group relative flex flex-col gap-4 rounded-lg border border-border bg-surface p-6 transition-colors hover:border-accent hover:bg-surface-2"
            href={href}
            key={href}
          >
            {soon && (
              <span className="absolute right-3 top-3 rounded border border-border px-2 py-0.5 text-xs text-muted-foreground">
                Bientôt
              </span>
            )}
            <Icon aria-hidden="true" className="size-8 text-accent-text" />
            <div>
              <p className="font-heading text-lg font-semibold text-foreground">{label}</p>
              <p className="mt-1 text-sm leading-6 text-muted-foreground">{description}</p>
            </div>
          </Link>
        ))}
      </div>
    </div>
  );
}

function StatCard({ label, value }: { label: string; value: string | null }) {
  return (
    <div className="rounded-lg border border-border bg-surface p-4">
      <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">{label}</p>
      <p className="mt-1 font-heading text-3xl font-bold text-foreground">
        {value === null ? <span className="text-xl text-muted-foreground">-</span> : value}
      </p>
    </div>
  );
}
