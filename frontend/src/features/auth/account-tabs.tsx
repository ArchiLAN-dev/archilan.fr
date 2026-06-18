"use client";

import { useEffect, useState } from "react";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { AccountRegistrations } from "./account-registrations";
import { DangerSection, DiscordSection, PrivacySection, SteamSection } from "./account-profile";
import { EmailVerificationBanner } from "./email-verification-banner";
import { PersonalRunsListPage } from "@/features/personal-runs/personal-runs-list-page";
import { CommunityProfileCustomizationForm } from "@/features/community/community-profile-customization-form";
import { CommunityFriendsPanel } from "@/features/community/community-friends-panel";
import { CommunityFeedPanel } from "@/features/community/community-activity";
import { MembershipSection } from "./membership-section";
import type { Profile } from "./account-profile";

// ── Types ─────────────────────────────────────────────────────────────────────

type Tab = "inscriptions" | "parties" | "activite" | "profil" | "amis" | "adhesion" | "confidentialite" | "compte";
type GroupId = "communaute" | "jeux" | "compte";

type SubTab = { id: Tab; label: string; danger?: true };
type Group = { id: GroupId; label: string; tabs: SubTab[] };

const GROUPS: Group[] = [
  {
    id: "communaute",
    label: "Communauté",
    tabs: [
      { id: "profil", label: "Profil" },
      { id: "amis", label: "Amis" },
      { id: "activite", label: "Activité" },
    ],
  },
  {
    id: "jeux",
    label: "Jeux",
    tabs: [
      { id: "inscriptions", label: "Inscriptions" },
      { id: "parties", label: "Mes parties" },
    ],
  },
  {
    id: "compte",
    label: "Compte",
    tabs: [
      { id: "adhesion", label: "Adhésion" },
      { id: "confidentialite", label: "Confidentialité" },
      { id: "compte", label: "Connexions & sécurité", danger: true },
    ],
  },
];

function groupOf(tab: Tab): Group {
  return GROUPS.find((g) => g.tabs.some((t) => t.id === tab)) ?? GROUPS[0];
}

// ── AccountTabs ───────────────────────────────────────────────────────────────

type AccountTabsProps = {
  discordLinked?: string;
  discordLinkError?: string;
};

export function AccountTabs({ discordLinked, discordLinkError }: AccountTabsProps) {
  const [activeTab, setActiveTab] = useState<Tab>("profil");
  const [profile, setProfile] = useState<Profile | null>(null);
  const [loadingProfile, setLoadingProfile] = useState(true);
  const activeGroup = groupOf(activeTab);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        const res = await apiFetch(`${env.apiBaseUrl}/account/profile`);
        const payload = (await res.json()) as
          | { data: Profile }
          | { error: { code: string; message: string } };
        if (!cancelled && "data" in payload) setProfile(payload.data);
      } finally {
        if (!cancelled) setLoadingProfile(false);
      }
    }

    void load();
    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <div className="grid gap-8">
      {/* ── Email verification banner ────────────────────────────────────── */}
      {!loadingProfile && profile && !profile.emailVerifiedAt && (
        <EmailVerificationBanner />
      )}

      {/* ── User header ─────────────────────────────────────────────────── */}
      <div className="card-glow flex items-center gap-4 rounded-xl border border-border p-5">
        <div
          aria-hidden="true"
          className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-accent/20 font-heading text-lg font-bold text-accent-text"
        >
          {loadingProfile ? "…" : getInitials(profile)}
        </div>
        <div className="min-w-0 flex-1">
          {loadingProfile ? (
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
        {!loadingProfile && profile && (
          <span className="shrink-0 rounded-full border border-border bg-surface px-3 py-1 text-xs font-medium text-muted-foreground">
            {formatRole(profile.roles)}
          </span>
        )}
      </div>

      {/* ── Two-level navigation + content ───────────────────────────────── */}
      <div className="grid gap-4">
        {/* Top level: groups */}
        <nav aria-label="Catégories de l'espace membre" className="flex flex-wrap gap-2" role="tablist">
          {GROUPS.map((group) => {
            const active = activeGroup.id === group.id;
            return (
              <button
                key={group.id}
                aria-selected={active}
                className={[
                  "min-h-9 rounded-full px-4 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60",
                  active
                    ? "bg-accent text-white"
                    : "border border-border text-muted-foreground hover:border-accent hover:text-foreground",
                ].join(" ")}
                role="tab"
                type="button"
                onClick={() => setActiveTab(group.tabs[0].id)}
              >
                {group.label}
              </button>
            );
          })}
        </nav>

        {/* Second level: sub-tabs of the active group */}
        <nav
          aria-label={`Sections : ${activeGroup.label}`}
          className="-mb-px flex overflow-x-auto border-b border-border"
          role="tablist"
        >
          {activeGroup.tabs.map((tab) => (
            <button
              key={tab.id}
              aria-selected={activeTab === tab.id}
              className={[
                "shrink-0 whitespace-nowrap border-b-2 px-4 py-3 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60",
                activeTab === tab.id
                  ? tab.danger
                    ? "border-danger text-danger"
                    : "border-accent text-foreground"
                  : tab.danger
                    ? "border-transparent text-muted-foreground hover:text-danger"
                    : "border-transparent text-muted-foreground hover:text-foreground",
              ].join(" ")}
              role="tab"
              type="button"
              onClick={() => setActiveTab(tab.id)}
            >
              {tab.label}
            </button>
          ))}
        </nav>

        <div role="tabpanel">
          {activeTab === "inscriptions" && <AccountRegistrations />}
          {activeTab === "parties" && <PersonalRunsListPage embedded />}
          {activeTab === "activite" && <CommunityFeedPanel />}
          {activeTab === "profil" && <CommunityProfileCustomizationForm />}
          {activeTab === "amis" && <CommunityFriendsPanel />}
          {activeTab === "adhesion" && <MembershipSection />}
          {activeTab === "confidentialite" && <PrivacySection />}
          {activeTab === "compte" && (
            <div className="grid gap-6">
              {!loadingProfile && (
                <DiscordSection
                  discordUsername={profile?.discordUsername ?? null}
                  linkFeedback={discordLinked === "1" ? "1" : discordLinkError}
                />
              )}
              {!loadingProfile && <SteamSection steamProfile={profile?.steamProfile ?? null} />}
              <DangerSection />
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

// ── Helpers ───────────────────────────────────────────────────────────────────

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
