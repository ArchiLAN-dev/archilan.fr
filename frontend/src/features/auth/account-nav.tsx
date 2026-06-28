"use client";

import { type LucideIcon, Gamepad2, LayoutDashboard, Settings, Users } from "lucide-react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";

// ── Navigation model ────────────────────────────────────────────────────────

type NavItem = { href: string; label: string; danger?: true; badgeKey?: "registrations" | "friends" };
type NavGroup = { id: string; label: string; icon: LucideIcon; items: NavItem[] };

const OVERVIEW = "/compte";

const GROUPS: NavGroup[] = [
  {
    id: "communaute",
    label: "Communauté",
    icon: Users,
    items: [
      { href: "/compte/profil", label: "Profil" },
      { href: "/compte/amis", label: "Amis", badgeKey: "friends" },
      { href: "/compte/activite", label: "Activité" },
    ],
  },
  {
    id: "jeux",
    label: "Jeux",
    icon: Gamepad2,
    items: [
      { href: "/compte/inscriptions", label: "Inscriptions", badgeKey: "registrations" },
      { href: "/compte/parties", label: "Mes parties" },
    ],
  },
  {
    id: "compte",
    label: "Compte",
    icon: Settings,
    items: [
      { href: "/compte/adhesion", label: "Adhésion" },
      { href: "/compte/confidentialite", label: "Confidentialité" },
      { href: "/compte/securite", label: "Connexions & sécurité", danger: true },
    ],
  },
];

// ── Component ────────────────────────────────────────────────────────────────

type AccountNavProps = {
  registrationsCount?: number;
  pendingFriends?: number;
};

export function AccountNav({ registrationsCount, pendingFriends }: AccountNavProps) {
  const pathname = usePathname();
  const router = useRouter();

  const badgeFor = (item: NavItem): number | undefined =>
    item.badgeKey === "registrations" ? registrationsCount
    : item.badgeKey === "friends" ? pendingFriends
    : undefined;

  return (
    <>
      {/* Mobile: a dropdown that navigates */}
      <label className="md:hidden">
        <span className="sr-only">Section de l&apos;espace membre</span>
        <select
          className="min-h-11 w-full rounded-lg border border-border bg-surface px-3 text-sm font-semibold text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60"
          onChange={(e) => router.push(e.target.value)}
          value={pathname}
        >
          <option value={OVERVIEW}>Vue d&apos;ensemble</option>
          {GROUPS.map((g) => (
            <optgroup key={g.id} label={g.label}>
              {g.items.map((it) => (
                <option key={it.href} value={it.href}>{it.label}</option>
              ))}
            </optgroup>
          ))}
        </select>
      </label>

      {/* Desktop: settings sidebar */}
      <nav aria-label="Espace membre" className="hidden md:block">
        <SidebarLink active={pathname === OVERVIEW} href={OVERVIEW}>
          <LayoutDashboard aria-hidden className="size-4 shrink-0" />
          <span>Vue d&apos;ensemble</span>
        </SidebarLink>

        {GROUPS.map((group) => {
          const Icon = group.icon;
          return (
            <div key={group.id} className="mt-5">
              <p className="mb-1 flex items-center gap-1.5 px-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                <Icon aria-hidden className="size-3.5 shrink-0" />
                {group.label}
              </p>
              <ul className="grid gap-0.5">
                {group.items.map((item) => {
                  const badge = badgeFor(item);
                  return (
                    <li key={item.href}>
                      <SidebarLink active={pathname === item.href} danger={item.danger} href={item.href}>
                        <span className="truncate">{item.label}</span>
                        {badge !== undefined && badge > 0 ? (
                          <span className="ml-auto inline-flex min-w-5 items-center justify-center rounded-full bg-accent/15 px-1.5 text-xs font-semibold text-accent-text">
                            {badge}
                          </span>
                        ) : null}
                      </SidebarLink>
                    </li>
                  );
                })}
              </ul>
            </div>
          );
        })}
      </nav>
    </>
  );
}

function SidebarLink({
  active,
  danger,
  href,
  children,
}: {
  active: boolean;
  danger?: true;
  href: string;
  children: React.ReactNode;
}) {
  return (
    <Link
      aria-current={active ? "page" : undefined}
      className={[
        "flex min-h-9 items-center gap-2 rounded-lg px-3 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60",
        active
          ? danger
            ? "bg-danger/10 font-semibold text-danger"
            : "bg-accent/15 font-semibold text-accent-text"
          : danger
            ? "text-muted-foreground hover:bg-surface-2 hover:text-danger"
            : "text-muted-foreground hover:bg-surface-2 hover:text-foreground",
      ].join(" ")}
      href={href}
    >
      {children}
    </Link>
  );
}
