"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useId, useRef, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { ChevronDown, LayoutDashboard, LogOut, Shield, User, Wallet } from "lucide-react";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchMyCommunityProfile } from "@/features/community/community-profile-api";
import { useAuth, type AuthUser } from "./auth-context";
import { MemberAvatar } from "../community/member-avatar";
import { PelleAmount } from "../wallet/pelle-amount";
import { fetchMyWallet } from "../wallet/wallet-api";

/**
 * Account dropdown for the desktop nav. Collapses everything that used to be a row of buttons
 * (profile, dashboard, admin, logout) under one avatar trigger, so the bar stays uncluttered as the
 * account surface grows. The notification bell stays a separate control beside it.
 *
 * The avatar shows the member's community profile photo, falling back to initials on a tinted disc
 * when there is none (or the image fails to load). The photo lives in the Community context, not in
 * the session, so it is fetched client-side under the same query key the settings form uses - shared
 * from cache rather than refetched. This GET does not create a profile row (editableForUser reads
 * with null-fallbacks), so putting it in the shell has no side effect.
 *
 * This is a disclosure, not an ARIA `menu`: the panel holds plain links plus a logout button, so tab
 * order and Enter behave natively. It closes on outside-pointer, on Escape, and on navigating.
 */
export function UserMenu({ user }: { user: AuthUser }) {
  const { setUser } = useAuth();
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const panelId = useId();
  const containerRef = useRef<HTMLDivElement>(null);

  const { data: profile } = useQuery({
    queryKey: ["community-my-profile"],
    queryFn: fetchMyCommunityProfile,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const avatarUrl = profile?.avatarUrl ?? null;

  // Story 41.1: the gold balance, under the same key as the wallet page's first page, so a credit seen
  // there shows here too.
  const { data: wallet } = useQuery({
    queryKey: ["my-wallet", 1],
    queryFn: () => fetchMyWallet(1),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  useEffect(() => {
    if (!open) return;

    function onPointerDown(event: PointerEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    }
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape") setOpen(false);
    }

    document.addEventListener("pointerdown", onPointerDown);
    document.addEventListener("keydown", onKeyDown);
    return () => {
      document.removeEventListener("pointerdown", onPointerDown);
      document.removeEventListener("keydown", onKeyDown);
    };
  }, [open]);

  async function handleLogout() {
    setOpen(false);
    await apiFetch(`${env.apiBaseUrl}/auth/logout`, { method: "POST" }).catch(() => {});
    setUser(null);
    router.push("/");
  }

  const isAdmin = user.roles.includes("ROLE_ADMIN");
  const name = user.displayName ?? "Mon compte";

  return (
    <div className="relative" ref={containerRef}>
      <button
        aria-controls={panelId}
        aria-expanded={open}
        aria-haspopup="true"
        className="flex min-h-11 items-center gap-2 rounded-lg border border-border py-1 pl-1 pr-2.5 text-sm font-semibold text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
        onClick={() => setOpen((value) => !value)}
        type="button"
      >
        {/* The one avatar animated for good off the profile page: the member's own, in the navigation bar (story 30.47). */}
        <MemberAvatar
          animate="always"
          avatarAnimatedUrl={profile?.avatarAnimatedUrl}
          avatarUrl={avatarUrl}
          frame={profile?.avatarFrame}
          framing={profile?.avatarFraming}
          name={user.displayName?.trim() || user.email}
          size={32}
        />
        <span className="max-w-32 truncate">{name}</span>
        <ChevronDown aria-hidden="true" className={`size-4 transition-transform ${open ? "rotate-180" : ""}`} />
      </button>

      {open ? (
        <div
          className="absolute right-0 top-[calc(100%+0.5rem)] z-50 w-60 overflow-hidden rounded-lg border border-border bg-background shadow-lg"
          id={panelId}
        >
          <div className="flex items-center gap-3 border-b border-border px-4 py-3">
            <MemberAvatar avatarAnimatedUrl={profile?.avatarAnimatedUrl} avatarUrl={avatarUrl} frame={profile?.avatarFrame} framing={profile?.avatarFraming} name={user.displayName?.trim() || user.email} size={40} />
            <div className="min-w-0">
              <p className="truncate text-sm font-semibold text-foreground">{name}</p>
              <p className="truncate text-xs text-muted-foreground">{user.email}</p>
            </div>
          </div>
          <div className="grid py-1">
            {user.slug ? (
              <MenuLink href={`/joueurs/${user.slug}`} icon={User} label="Mon profil" onNavigate={() => setOpen(false)} />
            ) : null}
            <MenuLink href="/compte" icon={LayoutDashboard} label="Mon espace" onNavigate={() => setOpen(false)} />
            <Link
              className="flex items-center justify-between gap-2.5 px-4 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-surface hover:text-foreground"
              href="/compte/portefeuille"
              onClick={() => setOpen(false)}
            >
              <span className="flex items-center gap-2.5">
                <Wallet aria-hidden="true" className="size-4" />
                Mon portefeuille
              </span>
              {wallet ? <PelleAmount amount={wallet.gold} className="font-semibold text-warning" /> : null}
            </Link>
            {isAdmin ? (
              <MenuLink href="/admin" icon={Shield} label="Administration" onNavigate={() => setOpen(false)} />
            ) : null}
          </div>
          <div className="border-t border-border py-1">
            <button
              className="flex w-full items-center gap-2.5 px-4 py-2 text-left text-sm font-medium text-muted-foreground transition-colors hover:bg-surface hover:text-foreground"
              onClick={handleLogout}
              type="button"
            >
              <LogOut aria-hidden="true" className="size-4" />
              Se déconnecter
            </button>
          </div>
        </div>
      ) : null}
    </div>
  );
}

function MenuLink({
  href,
  icon: Icon,
  label,
  onNavigate,
}: {
  href: string;
  icon: typeof User;
  label: string;
  onNavigate: () => void;
}) {
  return (
    <Link
      className="flex items-center gap-2.5 px-4 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-surface hover:text-foreground"
      href={href}
      onClick={onNavigate}
    >
      <Icon aria-hidden="true" className="size-4" />
      {label}
    </Link>
  );
}

/** Up to two letters for the avatar fallback: initials of the two first words, else two first chars. */
