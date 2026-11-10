import type { LucideIcon } from "lucide-react";
import { Bot, Calendar, CreditCard, Gamepad2, Gift, Handshake, LayoutDashboard, Library, Newspaper, Shield, Timer, Users } from "lucide-react";

/**
 * Story 3.20: the admin menu, 12 entries. An entry with tabs gathers pages that share a layout (a Next route group),
 * each at the address it always had; the entry leads to its first tab and lights up on any of them.
 */
export type AdminTab = { href: string; label: string; exact?: boolean };

export type AdminSection = {
  key: string;
  label: string;
  icon: LucideIcon;
  /** Where the menu entry leads, and the only page when there are no tabs. */
  href: string;
  exact?: boolean;
  tabs?: readonly AdminTab[];
};

export type AdminNavGroup = { label: string | null; sections: readonly AdminSection[] };

export const ADMIN_SECTIONS = {
  dashboard: {
    key: "dashboard",
    label: "Dashboard",
    icon: LayoutDashboard,
    href: "/admin",
    exact: true,
    tabs: [
      { href: "/admin", label: "Vue d'ensemble", exact: true },
      { href: "/admin/statistiques", label: "Statistiques" },
    ],
  },
  events: { key: "events", label: "Événements", icon: Calendar, href: "/admin/evenements" },
  publications: {
    key: "publications",
    label: "Publications",
    icon: Newspaper,
    href: "/admin/actualites",
    tabs: [
      // Story 10.14: the announcement opened as a modal on the home page.
      { href: "/admin/actualites", label: "Actualités" },
      { href: "/admin/annonces", label: "Annonces" },
    ],
  },
  // Story 45.1: the partners of the association.
  partners: { key: "partners", label: "Partenaires", icon: Handshake, href: "/admin/partenaires" },
  games: { key: "games", label: "Jeux", icon: Gamepad2, href: "/admin/jeux" },
  users: { key: "users", label: "Utilisateurs", icon: Users, href: "/admin/utilisateurs" },
  memberships: { key: "memberships", label: "Adhésions", icon: CreditCard, href: "/admin/adhesions" },
  rewards: {
    key: "rewards",
    label: "Récompenses",
    icon: Gift,
    href: "/admin/achievements",
    tabs: [
      { href: "/admin/achievements", label: "Succès" },
      { href: "/admin/boutique", label: "Boutique" },
      // Story 41.15: two sub-pages of their own (weeks, quest types).
      { href: "/admin/quetes", label: "Quêtes hebdo" },
      { href: "/admin/cadres", label: "Cadres" },
      { href: "/admin/bannieres", label: "Bannières" },
      // Story 41.22: profile titles, a text cosmetic.
      { href: "/admin/titres", label: "Titres" },
    ],
  },
  moderation: {
    key: "moderation",
    label: "Modération",
    icon: Shield,
    href: "/admin/moderation/signalements",
    // Story 39.13: each queue has its own page and its own count of what waits.
    tabs: [
      { href: "/admin/moderation/signalements", label: "Signalements" },
      { href: "/admin/moderation/contributions", label: "Contributions" },
    ],
  },
  apworlds: {
    key: "apworlds",
    label: "Apworlds",
    icon: Library,
    href: "/admin/catalogue",
    tabs: [
      { href: "/admin/catalogue", label: "Catalogue" },
      { href: "/admin/sante-apworlds", label: "Santé" },
      { href: "/admin/aide-archipelago", label: "Aide Archipelago" },
    ],
  },
  sessions: {
    key: "sessions",
    label: "Sessions",
    icon: Timer,
    href: "/admin/weekly-runs",
    tabs: [
      { href: "/admin/weekly-runs", label: "Runs hebdos" },
      { href: "/admin/sessions/config", label: "Config sessions" },
    ],
  },
  discord: { key: "discord", label: "Bot Discord", icon: Bot, href: "/admin/discord" },
} as const satisfies Record<string, AdminSection>;

export type AdminSectionKey = keyof typeof ADMIN_SECTIONS;

export const ADMIN_NAV_GROUPS: readonly AdminNavGroup[] = [
  { label: null, sections: [ADMIN_SECTIONS.dashboard] },
  {
    label: "Contenu",
    sections: [ADMIN_SECTIONS.events, ADMIN_SECTIONS.publications, ADMIN_SECTIONS.partners, ADMIN_SECTIONS.games],
  },
  {
    label: "Communauté",
    sections: [ADMIN_SECTIONS.users, ADMIN_SECTIONS.memberships, ADMIN_SECTIONS.rewards, ADMIN_SECTIONS.moderation],
  },
  { label: "Archipelago", sections: [ADMIN_SECTIONS.apworlds, ADMIN_SECTIONS.sessions] },
  { label: "Intégrations", sections: [ADMIN_SECTIONS.discord] },
];

export function isPathActive(pathname: string, href: string, exact = false): boolean {
  return exact ? pathname === href : pathname === href || pathname.startsWith(`${href}/`);
}

/** Whether the menu entry is lit: its own page, or any of its tabs (sub-pages included). */
export function isSectionActive(pathname: string, section: AdminSection): boolean {
  if (section.tabs) return section.tabs.some((tab) => isPathActive(pathname, tab.href, tab.exact));
  return isPathActive(pathname, section.href, section.exact);
}

/** Counts of what waits, keyed by page address. */
export type AdminBadges = Readonly<Record<string, number>>;

/** A menu entry carries the sum of its tabs' counts. */
export function sectionBadge(section: AdminSection, badges: AdminBadges): number {
  const hrefs = section.tabs ? section.tabs.map((tab) => tab.href) : [section.href];
  return hrefs.reduce((sum, href) => sum + (badges[href] ?? 0), 0);
}
