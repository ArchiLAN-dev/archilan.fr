"use client";

import Link from "next/link";
import Image from "next/image";
import { GridBackground } from "@/components/grid-background";
import { Menu, X } from "lucide-react";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useId, useState } from "react";
import { externalLinks } from "@/lib/external-links";
import { LiveTwitchBadge } from "@/features/streaming/live-twitch-badge";
import { TwitchPlayerProvider } from "@/features/streaming/twitch-player-context";
import { TwitchPersistentPlayer } from "@/features/streaming/twitch-mini-player";
import { AuthProvider, useAuth } from "@/features/auth/auth-context";
import { TwitchStatusProvider } from "@/features/streaming/twitch-status-context";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { QueryProvider } from "@/lib/query-provider";

const legalLinks = [
  { href: "/mentions-legales", label: "Mentions légales" },
  { href: "/confidentialite", label: "Confidentialité" },
  { href: "/cgu", label: "CGU" },
  { href: "/cgv", label: "CGV" },
] as const;

function isActive(pathname: string, href: string) {
  return href.startsWith("/") && (pathname === href || pathname.startsWith(`${href}/`));
}

function NavLink({
  href,
  label,
  onNavigate,
}: {
  href: string;
  label: string;
  onNavigate?: () => void;
}) {
  const pathname = usePathname();
  const external = href.startsWith("http://") || href.startsWith("https://");
  const active = isActive(pathname, href);
  const className = [
    "inline-flex min-h-11 items-center border-b-2 px-1 text-sm font-medium transition-all duration-200",
    active
      ? "border-accent text-foreground nav-active-glow"
      : "border-transparent text-muted-foreground hover:text-foreground",
  ].join(" ");

  if (external) {
    return (
      <a
        aria-label={`${label} (nouvel onglet)`}
        className={className}
        href={href}
        onClick={onNavigate}
        rel="noopener noreferrer"
        target="_blank"
      >
        {label}
      </a>
    );
  }

  return (
    <Link className={className} href={href} onClick={onNavigate}>
      {label}
    </Link>
  );
}

function AuthNavDesktop() {
  const { user, loading, setUser } = useAuth();
  const router = useRouter();

  async function handleLogout() {
    await apiFetch(`${env.apiBaseUrl}/auth/logout`, { method: "POST" }).catch(() => {});
    setUser(null);
    router.push("/");
  }

  if (loading) {
    return <div className="w-48" aria-hidden />;
  }

  if (user) {
    const isAdmin = user.roles.includes("ROLE_ADMIN");
    return (
      <div className="flex items-center gap-2">
        {isAdmin && (
          <Link
            className="inline-flex min-h-11 items-center rounded-lg border border-border px-4 text-sm font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
            href="/admin"
          >
            Admin
          </Link>
        )}
        <Link
          className="inline-flex min-h-11 items-center rounded-lg border border-border px-4 text-sm font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
          href="/runs-hebdo"
        >
          Runs hebdo
        </Link>
        <Link
          className="inline-flex min-h-11 items-center rounded-lg border border-border px-4 text-sm font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
          href="/compte"
        >
          Mon espace
        </Link>
        <button
          className="btn-glow inline-flex min-h-11 items-center rounded-lg bg-accent px-4 text-sm font-semibold text-white transition-all duration-300 hover:bg-accent-hover"
          type="button"
          onClick={handleLogout}
        >
          Se déconnecter
        </button>
      </div>
    );
  }

  return (
    <div className="flex items-center gap-2">
      <Link
        className="inline-flex min-h-11 items-center rounded-lg border border-border px-4 text-sm font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
        href="/connexion"
      >
        Connexion
      </Link>
      <Link
        className="btn-glow inline-flex min-h-11 items-center rounded-lg bg-accent px-4 text-sm font-semibold text-white transition-all duration-300 hover:bg-accent-hover"
        href="/inscription"
      >
        S&apos;inscrire
      </Link>
    </div>
  );
}

function AuthNavMobile({ onNavigate }: { onNavigate: () => void }) {
  const { user, loading, setUser } = useAuth();
  const router = useRouter();

  async function handleLogout() {
    await apiFetch(`${env.apiBaseUrl}/auth/logout`, { method: "POST" }).catch(() => {});
    setUser(null);
    onNavigate();
    router.push("/");
  }

  if (loading) {
    return <div className="h-12" aria-hidden />;
  }

  if (user) {
    const isAdmin = user.roles.includes("ROLE_ADMIN");
    return (
      <>
        {isAdmin && (
          <Link
            className="inline-flex min-h-12 items-center justify-center rounded border border-border px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
            href="/admin"
            onClick={onNavigate}
          >
            Administration
          </Link>
        )}
        <Link
          className="inline-flex min-h-12 items-center justify-center rounded border border-border px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
          href="/runs-hebdo"
          onClick={onNavigate}
        >
          Runs hebdo
        </Link>
        <Link
          className="inline-flex min-h-12 items-center justify-center rounded border border-border px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
          href="/compte"
          onClick={onNavigate}
        >
          Mon espace
        </Link>
        <button
          className="btn-glow inline-flex min-h-12 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white"
          type="button"
          onClick={handleLogout}
        >
          Se déconnecter
        </button>
      </>
    );
  }

  return (
    <>
      <Link
        className="inline-flex min-h-12 items-center justify-center rounded border border-border px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
        href="/connexion"
        onClick={onNavigate}
      >
        Connexion
      </Link>
      <Link
        className="btn-glow inline-flex min-h-12 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white"
        href="/inscription"
        onClick={onNavigate}
      >
        S&apos;inscrire
      </Link>
    </>
  );
}

export function PublicShell({ children }: Readonly<{ children: React.ReactNode }>) {
  const menuId = useId();
  const pathname = usePathname();
  const [menuState, setMenuState] = useState({ open: false, pathname });
  const open = menuState.open && menuState.pathname === pathname;

  useEffect(() => {
    document.body.classList.toggle("overflow-hidden", open);

    return () => document.body.classList.remove("overflow-hidden");
  }, [open]);

  return (
    <QueryProvider>
    <AuthProvider>
    <TwitchStatusProvider>
    <TwitchPlayerProvider>
    <div className="relative z-0 flex min-h-screen flex-col text-foreground">
      <GridBackground />
      <a className="skip-link" href="#main-content">
        Passer au contenu principal
      </a>

      <header
        className="sticky top-0 z-50 border-b border-border/80 bg-background/92 backdrop-blur"
        style={{ boxShadow: "0 1px 0 color-mix(in oklab, var(--color-accent) 18%, transparent)" }}
      >
        <nav
          aria-label="Navigation principale"
          className="mx-auto flex min-h-16 w-full max-w-7xl items-center justify-between px-6 md:px-12 lg:px-20"
        >
          <Link className="group flex min-h-11 items-center gap-2.5" href="/">
            <Image
              alt=""
              aria-hidden="true"
              className="size-9 shrink-0"
              height={36}
              src="/images/logo.webp"
              width={36}
            />
            <span className="font-heading text-lg font-semibold tracking-normal text-foreground">
              ArchiLAN
            </span>
          </Link>

          <div className="hidden items-center gap-6 lg:flex">
            <NavLink href="/evenements" label="Événements" />
            <NavLink href="/jeux" label="Jeux" />
            <NavLink href="/actualites" label="Actualités" />
            <NavLink href={externalLinks.archilanDiscord} label="Discord" />
            <LiveTwitchBadge />
          </div>

          <div className="hidden items-center gap-2 lg:flex">
            <AuthNavDesktop />
          </div>

          <button
            aria-controls={menuId}
            aria-expanded={open}
            aria-label={open ? "Fermer le menu" : "Ouvrir le menu"}
            className="inline-flex size-11 items-center justify-center rounded border border-border bg-surface text-foreground lg:hidden"
            type="button"
            onClick={() =>
              setMenuState((current) => ({
                open: !(current.open && current.pathname === pathname),
                pathname,
              }))
            }
          >
            {open ? <X aria-hidden="true" className="size-5" /> : <Menu aria-hidden="true" className="size-5" />}
          </button>
        </nav>

        <div
          aria-hidden={!open}
          className={[
            "fixed inset-x-0 top-16 z-40 h-[calc(100dvh-4rem)] overflow-y-auto bg-background px-6 py-8 transition lg:hidden",
            open ? "visible opacity-100" : "invisible pointer-events-none opacity-0",
          ].join(" ")}
          id={menuId}
        >
          <nav aria-label="Navigation mobile" className="flex h-full flex-col">
            <div className="flex flex-col gap-3">
              <NavLink href="/evenements" label="Événements" onNavigate={() => setMenuState({ open: false, pathname })} />
              <NavLink href="/jeux" label="Jeux" onNavigate={() => setMenuState({ open: false, pathname })} />
              <NavLink href="/actualites" label="Actualités" onNavigate={() => setMenuState({ open: false, pathname })} />
              <NavLink href={externalLinks.archilanDiscord} label="Discord" onNavigate={() => setMenuState({ open: false, pathname })} />
              <LiveTwitchBadge onNavigate={() => setMenuState({ open: false, pathname })} />
            </div>
            <div className="mt-auto grid gap-3 border-t border-border pt-6">
              <AuthNavMobile onNavigate={() => setMenuState({ open: false, pathname })} />
            </div>
          </nav>
        </div>
      </header>

      <main className="w-full flex-1 px-6 py-16 md:px-12 lg:px-20" id="main-content">
        {children}
      </main>

      <footer className="border-t border-border bg-background/92 backdrop-blur">
        <div className="mx-auto flex w-full max-w-7xl flex-col gap-4 px-6 py-8 text-sm text-muted-foreground md:flex-row md:items-center md:justify-between md:gap-6 md:px-12 lg:px-20">
          <div className="flex items-center gap-2.5">
            <Image
              alt=""
              aria-hidden="true"
              className="size-6 shrink-0 opacity-70"
              height={24}
              src="/images/logo.webp"
              width={24}
            />
            <p>© ArchiLAN. Association gaming et Archipelago.</p>
          </div>
          <nav aria-label="Liens légaux" className="flex flex-wrap items-center gap-x-4 gap-y-2">
            {legalLinks.map((link, i) => (
              <span className="contents" key={link.href}>
                {i > 0 && <span aria-hidden="true" className="select-none text-muted-foreground/40">·</span>}
                <Link className="inline-flex items-center hover:text-foreground" href={link.href}>
                  {link.label}
                </Link>
              </span>
            ))}
          </nav>
        </div>
      </footer>
      <TwitchPersistentPlayer />
    </div>
    </TwitchPlayerProvider>
    </TwitchStatusProvider>
    </AuthProvider>
    </QueryProvider>
  );
}
