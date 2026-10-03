"use client";

import Link from "next/link";
import Image from "next/image";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ArrowLeft, BookOpen, Bot, Calendar, ChartColumn, CreditCard, Flag, Frame, Gamepad2, HeartPulse, LayoutDashboard, Library, LogOut, Menu, Newspaper, NotebookPen, SlidersHorizontal, Store, Timer, Trophy, Users, X } from "lucide-react";
import { APWORLD_INCIDENT_SUMMARY_QUERY_KEY, fetchApworldIncidentSummary } from "@/features/admin/admin-apworld-health-api";
import { DEFAULT_CONTRIBUTION_FILTERS, fetchContributionQueue } from "@/features/admin/admin-game-contributions-api";
import { DEFAULT_REPORT_FILTERS, fetchModerationQueue } from "@/features/admin/admin-moderation-api";
import { AuthProvider, useAuth } from "@/features/auth/auth-context";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { QueryProvider } from "@/lib/query-provider";

const navGroups = [
  {
    label: null,
    items: [
      { href: "/admin", icon: LayoutDashboard, label: "Dashboard", shortLabel: "Home", exact: true },
      { href: "/admin/statistiques", icon: ChartColumn, label: "Statistiques", shortLabel: "Stats", exact: false },
    ],
  },
  {
    label: "Contenu",
    items: [
      { href: "/admin/evenements", icon: Calendar, label: "Événements", shortLabel: "Events", exact: false },
      { href: "/admin/actualites", icon: Newspaper, label: "Actualités", shortLabel: "Actus", exact: false },
      { href: "/admin/jeux", icon: Gamepad2, label: "Jeux", shortLabel: "Jeux", exact: false },
    ],
  },
  {
    label: "Communauté",
    items: [
      { href: "/admin/utilisateurs", icon: Users, label: "Utilisateurs", shortLabel: "Users", exact: false },
      { href: "/admin/adhesions", icon: CreditCard, label: "Adhésions", shortLabel: "Adhés.", exact: false },
      { href: "/admin/achievements", icon: Trophy, label: "Succès", shortLabel: "Succès", exact: false },
      { href: "/admin/boutique", icon: Store, label: "Boutique", shortLabel: "Boutique", exact: false },
      { href: "/admin/cadres", icon: Frame, label: "Cadres", shortLabel: "Cadres", exact: false },
    ],
  },
  {
    // Story 39.13: each moderation queue has its own page and its own count of what waits.
    label: "Modération",
    items: [
      { href: "/admin/moderation/signalements", icon: Flag, label: "Signalements", shortLabel: "Signal.", exact: false },
      { href: "/admin/moderation/contributions", icon: NotebookPen, label: "Contributions", shortLabel: "Contrib.", exact: false },
    ],
  },
  {
    label: "Archipelago",
    items: [
      { href: "/admin/catalogue", icon: Library, label: "Catalogue", shortLabel: "Catalogue", exact: false },
      { href: "/admin/sante-apworlds", icon: HeartPulse, label: "Santé apworlds", shortLabel: "Santé", exact: false },
      { href: "/admin/aide-archipelago", icon: BookOpen, label: "Aide Archipelago", shortLabel: "Aide", exact: false },
      { href: "/admin/weekly-runs", icon: Timer, label: "Runs hebdos", shortLabel: "Runs", exact: false },
      { href: "/admin/sessions/config", icon: SlidersHorizontal, label: "Config sessions", shortLabel: "Config", exact: false },
    ],
  },
  {
    label: "Intégrations",
    items: [
      { href: "/admin/discord", icon: Bot, label: "Bot Discord", shortLabel: "Discord", exact: false },
    ],
  },
] as const;

function isItemActive(pathname: string, href: string, exact: boolean): boolean {
  return exact ? pathname === href : pathname === href || pathname.startsWith(`${href}/`);
}

/** Count of items waiting for someone, next to a menu entry. Nothing when there are none. */
function NavBadge({ count }: { count: number }) {
  if (count <= 0) return null;
  return (
    <span
      aria-label={`${count} à prendre en charge`}
      className="ml-auto inline-flex min-w-5 items-center justify-center rounded-full bg-danger px-1.5 text-[11px] font-bold text-white"
    >
      {count}
    </span>
  );
}

function AdminShellSkeleton() {
  return (
    <div className="flex min-h-screen bg-background">
      <div className="hidden w-60 shrink-0 bg-surface lg:block" />
      <div className="flex flex-1 animate-pulse flex-col gap-4 p-8">
        <div className="h-8 w-48 rounded bg-surface-2" />
        <div className="h-4 w-64 rounded bg-surface-2" />
        <div className="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
          {[...Array(4)].map((_, i) => (
            <div className="h-32 rounded-lg bg-surface-2" key={i} />
          ))}
        </div>
      </div>
    </div>
  );
}

function AdminAccessDenied() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-background p-6">
      <div className="max-w-md text-center">
        <h1 className="font-heading text-2xl font-bold text-foreground">
          Accès réservé aux admins
        </h1>
        <p className="mt-3 text-muted-foreground">
          Vous n&apos;avez pas les droits pour accéder à cette section.
        </p>
        <Link
          className="mt-6 inline-flex min-h-11 items-center justify-center rounded bg-accent px-5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
          href="/"
        >
          Retour au site public
        </Link>
      </div>
    </div>
  );
}

function AdminShellInner({ children }: { children: React.ReactNode }) {
  const { user, loading: authLoading, setUser } = useAuth();
  const pathname = usePathname();
  const router = useRouter();
  const [menuState, setMenuState] = useState({ open: false, pathname });
  const mobileMenuOpen = menuState.open && menuState.pathname === pathname;

  // Story 38.3: apworld incidents nobody has taken yet, as a badge on the health page entry. Declared
  // before the early returns (rules of hooks), and only fetched for an admin.
  const isAdmin = user?.roles.includes("ROLE_ADMIN") ?? false;
  const { data: apworldSummary } = useQuery({
    queryKey: APWORLD_INCIDENT_SUMMARY_QUERY_KEY,
    queryFn: fetchApworldIncidentSummary,
    enabled: isAdmin,
    staleTime: 60_000,
  });
  // Story 39.13: what waits in each moderation queue (same queries, hence same cache, as their pages).
  const { data: pendingReports } = useQuery({
    queryKey: ["admin-moderation", "pending-count"],
    queryFn: () => fetchModerationQueue(DEFAULT_REPORT_FILTERS),
    enabled: isAdmin,
    staleTime: 60_000,
  });
  const { data: pendingContributions } = useQuery({
    queryKey: ["admin-game-contributions", "pending-count"],
    queryFn: () => fetchContributionQueue(DEFAULT_CONTRIBUTION_FILTERS),
    enabled: isAdmin,
    staleTime: 60_000,
  });
  const badges: Record<string, number> = {
    "/admin/sante-apworlds": apworldSummary?.unacknowledged ?? 0,
    "/admin/moderation/signalements": pendingReports?.count ?? 0,
    "/admin/moderation/contributions": pendingContributions?.count ?? 0,
  };

  useEffect(() => {
    document.body.classList.toggle("overflow-hidden", mobileMenuOpen);
    return () => document.body.classList.remove("overflow-hidden");
  }, [mobileMenuOpen]);

  useEffect(() => {
    if (authLoading) return;
    if (!user) {
      router.push(`/connexion?returnTo=${encodeURIComponent(pathname)}`);
    }
  }, [authLoading, user, router, pathname]);

  async function handleLogout() {
    await apiFetch(`${env.apiBaseUrl}/auth/logout`, { method: "POST" }).catch(
      () => {},
    );
    setUser(null);
    router.push("/connexion");
  }

  if (authLoading) return <AdminShellSkeleton />;
  if (!user) return null;
  if (!user.roles.includes("ROLE_ADMIN")) return <AdminAccessDenied />;

  return (
    <div className="flex min-h-screen bg-background">
      {/* Desktop sidebar - visible ≥1024px */}
      <aside
        aria-label="Navigation administration"
        className="fixed inset-y-0 left-0 z-40 hidden w-60 flex-col border-r border-border bg-surface lg:flex"
      >
        <div className="flex items-center gap-2.5 border-b border-border px-4 py-4">
          <Image
            alt=""
            aria-hidden="true"
            className="size-8 shrink-0"
            height={32}
            src="/images/logo.webp"
            width={32}
          />
          <div>
            <p className="font-heading text-sm font-bold text-foreground">ArchiLAN</p>
            <p className="text-xs text-muted-foreground">Administration</p>
          </div>
        </div>

        <nav aria-label="Sections" className="flex flex-1 flex-col gap-4 overflow-y-auto p-3">
          {navGroups.map((group) => (
            <div className="flex flex-col gap-1" key={group.label ?? "top"}>
              {group.label ? (
                <p className="px-3 pb-1 pt-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground/60">
                  {group.label}
                </p>
              ) : null}
              {group.items.map(({ href, icon: Icon, label, exact }) => {
                const active = isItemActive(pathname, href, exact);
                return (
                  <Link
                    aria-current={active ? "page" : undefined}
                    className={[
                      "flex items-center gap-3 rounded px-3 py-2 text-sm font-medium transition-colors",
                      active
                        ? "border-l-2 border-accent bg-surface-2 text-foreground"
                        : "text-muted-foreground hover:bg-surface-2 hover:text-foreground",
                    ].join(" ")}
                    href={href}
                    key={href}
                  >
                    <Icon aria-hidden="true" className="size-4 shrink-0" />
                    {label}
                    <NavBadge count={badges[href] ?? 0} />
                  </Link>
                );
              })}
            </div>
          ))}
        </nav>

        <div className="flex flex-col gap-1 border-t border-border p-3">
          <Link
            className="flex items-center gap-2 rounded px-3 py-2 text-sm text-muted-foreground transition-colors hover:text-foreground"
            href="/"
          >
            <ArrowLeft aria-hidden="true" className="size-4" />
            Site public
          </Link>
          <button
            className="flex w-full items-center gap-2 rounded px-3 py-2 text-sm text-muted-foreground transition-colors hover:text-danger"
            onClick={handleLogout}
            type="button"
          >
            <LogOut aria-hidden="true" className="size-4" />
            Se déconnecter
          </button>
        </div>
      </aside>

      {/* Tablet sidebar - icon-only, 48px, visible 768–1023px */}
      <aside
        aria-hidden="true"
        className="fixed inset-y-0 left-0 z-40 hidden w-12 flex-col border-r border-border bg-surface md:flex lg:hidden"
      >
        <div className="flex items-center justify-center border-b border-border py-4">
          <Image
            alt=""
            aria-hidden="true"
            className="size-7 shrink-0"
            height={28}
            src="/images/logo.webp"
            width={28}
          />
        </div>

        <nav className="flex flex-1 flex-col items-center gap-1 overflow-y-auto py-3">
          {navGroups.map((group, groupIndex) => (
            <div className="flex flex-col items-center gap-1" key={group.label ?? "top"}>
              {groupIndex > 0 ? <div className="my-1 h-px w-6 bg-border" /> : null}
              {group.items.map(({ href, icon: Icon, label, exact }) => {
                const active = isItemActive(pathname, href, exact);
                return (
                  <Link
                    className={[
                      "relative flex size-10 items-center justify-center rounded transition-colors",
                      active
                        ? "bg-surface-2 text-foreground"
                        : "text-muted-foreground hover:bg-surface-2 hover:text-foreground",
                    ].join(" ")}
                    href={href}
                    key={href}
                    title={label}
                  >
                    <Icon aria-hidden="true" className="size-5" />
                    {(badges[href] ?? 0) > 0 ? (
                      <span aria-hidden="true" className="absolute right-1.5 top-1.5 size-2 rounded-full bg-danger" />
                    ) : null}
                  </Link>
                );
              })}
            </div>
          ))}
        </nav>

        <div className="flex flex-col items-center gap-1 border-t border-border py-3">
          <Link
            className="flex size-10 items-center justify-center rounded text-muted-foreground transition-colors hover:text-foreground"
            href="/"
            title="Site public"
          >
            <ArrowLeft aria-hidden="true" className="size-5" />
          </Link>
          <button
            className="flex size-10 items-center justify-center rounded text-muted-foreground transition-colors hover:text-danger"
            onClick={handleLogout}
            title="Se déconnecter"
            type="button"
          >
            <LogOut aria-hidden="true" className="size-5" />
          </button>
        </div>
      </aside>

      {/* Mobile header - visible <768px */}
      <header className="fixed inset-x-0 top-0 z-40 flex h-14 items-center justify-between border-b border-border bg-surface px-4 md:hidden">
        <div className="flex items-center gap-2.5">
          <Image
            alt=""
            aria-hidden="true"
            className="size-7 shrink-0"
            height={28}
            src="/images/logo.webp"
            width={28}
          />
          <span className="font-heading text-sm font-bold text-foreground">Administration</span>
        </div>
        <button
          aria-expanded={mobileMenuOpen}
          aria-label={mobileMenuOpen ? "Fermer le menu" : "Ouvrir le menu"}
          className="inline-flex size-10 items-center justify-center rounded border border-border text-foreground transition-colors hover:border-accent"
          type="button"
          onClick={() => setMenuState((s) => ({ open: !(s.open && s.pathname === pathname), pathname }))}
        >
          {mobileMenuOpen ? (
            <X aria-hidden="true" className="size-5" />
          ) : (
            <Menu aria-hidden="true" className="size-5" />
          )}
        </button>
      </header>

      {/* Mobile menu overlay */}
      <div
        aria-hidden={!mobileMenuOpen}
        className={[
          "fixed inset-0 top-14 z-40 bg-background md:hidden",
          mobileMenuOpen ? "visible opacity-100" : "invisible pointer-events-none opacity-0",
          "transition",
        ].join(" ")}
      >
        <nav aria-label="Navigation administration mobile" className="flex h-full flex-col overflow-y-auto p-4">
          <div className="flex flex-col gap-4">
            {navGroups.map((group) => (
              <div className="flex flex-col gap-1" key={group.label ?? "top"}>
                {group.label ? (
                  <p className="px-4 pb-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground/60">
                    {group.label}
                  </p>
                ) : null}
                {group.items.map(({ href, icon: Icon, label, exact }) => {
                  const active = isItemActive(pathname, href, exact);
                  return (
                    <Link
                      aria-current={active ? "page" : undefined}
                      className={[
                        "flex items-center gap-3 border-l-2 px-4 py-3 text-sm font-medium transition-colors",
                        active
                          ? "border-accent bg-surface text-foreground"
                          : "border-transparent text-muted-foreground hover:bg-surface hover:text-foreground",
                      ].join(" ")}
                      href={href}
                      key={href}
                    >
                      <Icon aria-hidden="true" className="size-5 shrink-0" />
                      {label}
                      <NavBadge count={badges[href] ?? 0} />
                    </Link>
                  );
                })}
              </div>
            ))}
          </div>
          <div className="mt-auto flex flex-col gap-1 border-t border-border pt-4">
            <Link
              className="flex items-center gap-3 px-4 py-3 text-sm text-muted-foreground transition-colors hover:text-foreground"
              href="/"
            >
              <ArrowLeft aria-hidden="true" className="size-5" />
              Site public
            </Link>
            <button
              className="flex items-center gap-3 px-4 py-3 text-sm text-muted-foreground transition-colors hover:text-danger"
              type="button"
              onClick={handleLogout}
            >
              <LogOut aria-hidden="true" className="size-5" />
              Se déconnecter
            </button>
          </div>
        </nav>
      </div>

      {/* Content area */}
      <main
        className="flex-1 overflow-auto pt-14 md:ml-12 md:pt-0 lg:ml-60"
        id="admin-main-content"
      >
        {children}
      </main>
    </div>
  );
}

export function AdminShell({ children }: { children: React.ReactNode }) {
  return (
    <QueryProvider>
      <AuthProvider>
        <AdminShellInner>{children}</AdminShellInner>
      </AuthProvider>
    </QueryProvider>
  );
}
