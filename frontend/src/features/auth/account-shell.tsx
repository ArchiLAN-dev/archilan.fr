"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchMyCommunityProfile } from "@/features/community/community-profile-api";
import { fetchFriends } from "@/features/community/community-friends-api";
import { fetchAccountProfile, fetchAccountRegistrations } from "./auth-api";
import { AccountNav } from "./account-nav";
import { EmailVerificationBanner } from "./email-verification-banner";
import type { Profile } from "./account-profile";

/**
 * Shared chrome for every `/compte/*` section: fetches the profile once (header, role, email banner)
 * and the sidebar badge counts, then lays out the nav + the active section ({children}).
 * All fetchers resolve to null on error (never throw), so retry stays off like the old effects.
 */
export function AccountShell({ children }: { children: React.ReactNode }) {
  const { data: profileData, isLoading: loadingProfile } = useQuery({
    queryKey: ["account-profile"],
    queryFn: fetchAccountProfile,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const { data: communityProfile, isLoading: loadingCommunity } = useQuery({
    queryKey: ["community-my-profile"],
    queryFn: fetchMyCommunityProfile,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  // Sidebar badges (best-effort, independent of the header load - errors just leave them hidden).
  const { data: friends } = useQuery({
    queryKey: ["community-friends"],
    queryFn: fetchFriends,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const { data: registrations } = useQuery({
    queryKey: ["account-registrations"],
    queryFn: fetchAccountRegistrations,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  const profile: Profile | null = profileData ?? null;
  const avatarUrl = communityProfile?.avatarUrl ?? null;
  const loading = loadingProfile || loadingCommunity;
  const pendingFriends = friends ? friends.incoming.length : undefined;
  const registrationsCount = registrations ? registrations.length : undefined;

  return (
    <div className="grid gap-6">
      {!loading && profile && !profile.emailVerifiedAt && <EmailVerificationBanner />}

      {/* User header */}
      <div className="card-glow flex items-center gap-4 rounded-xl border border-border p-5">
        {loading ? (
          <div
            aria-hidden="true"
            className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-accent/20 font-heading text-lg font-bold text-accent-text"
          >
            …
          </div>
        ) : (
          <HeaderAvatar avatarUrl={avatarUrl} initials={getInitials(profile)} />
        )}
        <div className="min-w-0 flex-1">
          {loading ? (
            <div className="grid gap-2">
              <div className="h-5 w-36 animate-pulse rounded bg-surface" />
              <div className="h-4 w-48 animate-pulse rounded bg-surface" />
            </div>
          ) : (
            <>
              <p className="truncate font-heading text-lg font-semibold text-foreground">
                {profile?.displayName ?? "-"}
              </p>
              <p className="truncate text-sm text-muted-foreground">{profile?.email ?? ""}</p>
            </>
          )}
        </div>
        {!loading && profile && (
          <span className="shrink-0 rounded-full border border-border bg-surface px-3 py-1 text-xs font-medium text-muted-foreground">
            {formatRole(profile.roles)}
          </span>
        )}
      </div>

      {/* Sidebar + active section */}
      <div className="grid gap-6 md:grid-cols-[13rem_1fr] md:items-start">
        <AccountNav pendingFriends={pendingFriends} registrationsCount={registrationsCount} />
        <div className="min-w-0">{children}</div>
      </div>
    </div>
  );
}

// ── Helpers (moved from the former AccountTabs) ─────────────────────────────────

function HeaderAvatar({ avatarUrl, initials }: { avatarUrl: string | null; initials: string }) {
  const [failed, setFailed] = useState(false);

  if (avatarUrl !== null && !failed) {
    return (
      // eslint-disable-next-line @next/next/no-img-element -- external/presigned avatar URL, not a local asset
      <img
        alt=""
        aria-hidden="true"
        className="h-14 w-14 shrink-0 rounded-full bg-surface object-cover"
        onError={() => setFailed(true)}
        src={avatarUrl}
      />
    );
  }

  return (
    <div
      aria-hidden="true"
      className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-accent/20 font-heading text-lg font-bold text-accent-text"
    >
      {initials}
    </div>
  );
}

function getInitials(profile: Profile | null): string {
  if (!profile) return "?";
  if (profile.displayName) {
    return profile.displayName
      .split(" ")
      .slice(0, 2)
      .map((w) => w[0]?.toUpperCase() ?? "")
      .join("");
  }
  return (profile.email[0] ?? "?").toUpperCase();
}

function formatRole(roles: string[]): string {
  if (roles.includes("ROLE_ADMIN")) return "Admin";
  if (roles.includes("ROLE_MEMBER")) return "Membre";
  return "Utilisateur";
}
