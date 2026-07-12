"use client";

import { useQuery } from "@tanstack/react-query";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchAccountProfile } from "./auth-api";
import { DangerSection, DiscordSection, SteamSection } from "./account-profile";

/**
 * "Connexions & sécurité" section: Discord/Steam linking + account deletion. Fetches the profile itself
 * (needs discordUsername/steamProfile); `discordLinked`/`discordLinkError` come from the OAuth callback.
 */
export function AccountSecuritySection({
  discordLinked,
  discordLinkError,
}: {
  discordLinked?: string;
  discordLinkError?: string;
}) {
  // fetchAccountProfile resolves to null on error (never throws), so retry stays off like the old effect.
  const { data, isLoading } = useQuery({
    queryKey: ["account-profile"],
    queryFn: fetchAccountProfile,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const profile = data ?? null;

  if (isLoading) {
    return (
      <div aria-hidden className="grid gap-4">
        <div className="h-32 animate-pulse rounded-lg border border-border bg-surface" />
        <div className="h-24 animate-pulse rounded-lg border border-border bg-surface" />
      </div>
    );
  }

  return (
    <div className="grid gap-6">
      <DiscordSection
        discordUsername={profile?.discordUsername ?? null}
        linkFeedback={discordLinked === "1" ? "1" : discordLinkError}
      />
      <SteamSection steamProfile={profile?.steamProfile ?? null} />
      <DangerSection />
    </div>
  );
}
