"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchMyCommunityProfile } from "@/features/community/community-profile-api";
import { fetchFriends } from "@/features/community/community-friends-api";
import { getAccountMembership } from "@/features/payments/membership-api";
import { fetchAccountProfile, fetchAccountRegistrations } from "./auth-api";
import { accountRoleLabel } from "./account-role";
import { AccountNav } from "./account-nav";
import { EmailVerificationBanner } from "./email-verification-banner";
import type { Profile } from "./account-profile";
import { AvatarImage } from "../community/avatar-image";
import type { ImageFraming } from "@/features/community/image-framing";

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

  // Same key as the membership section, so the label and the section never disagree (story 22.7).
  const { data: membership } = useQuery({
    queryKey: ["account-membership"],
    queryFn: getAccountMembership,
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

  // `grid-cols-1` bounds the column to the screen: an implicit track grows to the identity card's
  // min-content and made every `/compte/*` page scroll sideways on a phone.
  return (
    <div className="grid grid-cols-1 gap-6">
      {!loading && profile && !profile.emailVerifiedAt && <EmailVerificationBanner />}

      {/* User header */}
      <div className="card-glow flex items-center gap-4 rounded-xl border border-border p-4 md:p-5">
        {loading ? (
          <div
            aria-hidden="true"
            className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-accent/20 font-heading text-lg font-bold text-accent-text"
          >
            …
          </div>
        ) : (
          <HeaderAvatar animatedUrl={communityProfile?.avatarAnimatedUrl} avatarUrl={avatarUrl} framing={communityProfile?.avatarFraming} initials={getInitials(profile)} />
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
            {accountRoleLabel(profile.roles, membership?.status)}
          </span>
        )}
      </div>

      {/* Sidebar + active section */}
      <div className="grid grid-cols-1 gap-6 md:grid-cols-[13rem_1fr] md:items-start">
        <AccountNav pendingFriends={pendingFriends} registrationsCount={registrationsCount} />
        <div className="min-w-0">{children}</div>
      </div>
    </div>
  );
}

// ── Helpers (moved from the former AccountTabs) ─────────────────────────────────

function HeaderAvatar({ avatarUrl, animatedUrl = null, framing = null, initials }: { avatarUrl: string | null; animatedUrl?: string | null; framing?: ImageFraming | null; initials: string }) {
  const [failed, setFailed] = useState(false);

  if (avatarUrl !== null && !failed) {
    return (
      <AvatarImage
        animatedSrc={animatedUrl}
        className="h-14 w-14 shrink-0 rounded-full bg-surface object-cover"
        framing={framing}
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
