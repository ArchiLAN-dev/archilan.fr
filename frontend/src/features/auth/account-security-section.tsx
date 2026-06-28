"use client";

import { useEffect, useState } from "react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { DangerSection, DiscordSection, SteamSection } from "./account-profile";
import type { Profile } from "./account-profile";

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
  const [profile, setProfile] = useState<Profile | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      try {
        const res = await apiFetch(`${env.apiBaseUrl}/account/profile`);
        const payload = (await res.json()) as
          | { data: Profile }
          | { error: { code: string; message: string } };
        if (!cancelled && "data" in payload) setProfile(payload.data);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, []);

  if (loading) {
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
