"use client";

import { type LucideIcon, Gamepad2, Settings, Users } from "lucide-react";
import { useEffect, useState } from "react";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { AccountRegistrations } from "./account-registrations";
import { DangerSection, DiscordSection, PrivacySection, SteamSection } from "./account-profile";
import { EmailVerificationBanner } from "./email-verification-banner";
import { PersonalRunsListPage } from "@/features/personal-runs/personal-runs-list-page";
import { fetchMyCommunityProfile } from "@/features/community/community-profile-api";
import { CommunityProfileCustomizationForm } from "@/features/community/community-profile-customization-form";
import { CommunityFriendsPanel } from "@/features/community/community-friends-panel";
import { CommunityFeedPanel } from "@/features/community/community-activity";
import { MembershipSection } from "./membership-section";
import type { Profile } from "./account-profile";

// ── Types ─────────────────────────────────────────────────────────────────────

type Tab = "inscriptions" | "parties" | "activite" | "profil" | "amis" | "adhesion" | "confidentialite" | "compte";
type GroupId = "communaute" | "jeux" | "compte";

type SubTab = { id: Tab; label: string; danger?: true };
type Group = { id: GroupId; label: string; icon: LucideIcon; tabs: SubTab[] };

const GROUPS: Group[] = [
  {
    id: "communaute",
    label: "Communauté",
    icon: Users,
    tabs: [
      { id: "profil", label: "Profil" },
      { id: "amis", label: "Amis" },
      { id: "activite", label: "Activité" },
    ],
  },
  {
    id: "jeux",
    label: "Jeux",
    icon: Gamepad2,
    tabs: [
      { id: "inscriptions", label: "Inscriptions" },
      { id: "parties", label: "Mes parties" },
    ],
  },
  {
    id: "compte",
    label: "Compte",
    icon: Settings,
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

const ALL_TAB_IDS: readonly Tab[] = GROUPS.flatMap((g) => g.tabs.map((t) => t.id));

function isTab(value: string): value is Tab {
  return ALL_TAB_IDS.some((id) => id === value);
}

// ── AccountTabs ───────────────────────────────────────────────────────────────

type AccountTabsProps = {
  discordLinked?: string;
  discordLinkError?: string;
  initialTab?: string;
};

export function AccountTabs({ discordLinked, discordLinkError, initialTab }: AccountTabsProps) {
  const [activeTab, setActiveTab] = useState<Tab>(() => {
    if (initialTab && isTab(initialTab)) return initialTab;
    // The Discord OAuth callback redirects back here - land on Connexions & sécurité.
    if (discordLinked || discordLinkError) return "compte";
    return "profil";
  });
  const [profile, setProfile] = useState<Profile | null>(null);
  const [avatarUrl, setAvatarUrl] = useState<string | null>(null);
  const [loadingProfile, setLoadingProfile] = useState(true);
  const activeGroup = groupOf(activeTab);

  // Persist the active tab in the URL (?tab=) so a reload / deep link reopens it. replaceState keeps
  // it client-side (no navigation/refetch) and out of the history stack.
  function selectTab(tab: Tab) {
    setActiveTab(tab);
    if (typeof window !== "undefined") {
      const url = new URL(window.location.href);
      url.searchParams.set("tab", tab);
      window.history.replaceState(null, "", url);
    }
  }

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        // Account data (email/roles) comes from Identity; the resolved avatar from the community profile.
        const [res, community] = await Promise.all([
          apiFetch(`${env.apiBaseUrl}/account/profile`),
          fetchMyCommunityProfile(),
        ]);
        const payload = (await res.json()) as
          | { data: Profile }
          | { error: { code: string; message: string } };
        if (!cancelled && "data" in payload) setProfile(payload.data);
        if (!cancelled && community) setAvatarUrl(community.avatarUrl);
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
        {loadingProfile ? (
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
        {/* Top level: segmented control of groups */}
        <nav
          aria-label="Catégories de l'espace membre"
          className="grid grid-cols-3 gap-1 rounded-xl border border-border bg-surface p-1"
          role="tablist"
        >
          {GROUPS.map((group) => {
            const active = activeGroup.id === group.id;
            const Icon = group.icon;
            return (
              <button
                key={group.id}
                aria-selected={active}
                className={[
                  "inline-flex min-h-10 min-w-0 items-center justify-center gap-2 rounded-lg px-3 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60",
                  active
                    ? "bg-accent text-white shadow-sm"
                    : "text-muted-foreground hover:bg-surface-2 hover:text-foreground",
                ].join(" ")}
                role="tab"
                type="button"
                onClick={() => selectTab(group.tabs[0].id)}
              >
                <Icon aria-hidden className="size-4 shrink-0" />
                <span className="truncate">{group.label}</span>
              </button>
            );
          })}
        </nav>

        {/* Second level (mobile): a dropdown of the active group's sections */}
        <label className="sm:hidden">
          <span className="sr-only">{`Sections : ${activeGroup.label}`}</span>
          <select
            className="min-h-11 w-full rounded-lg border border-border bg-surface px-3 text-sm font-semibold text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60"
            value={activeTab}
            onChange={(e) => { if (isTab(e.target.value)) selectTab(e.target.value); }}
          >
            {activeGroup.tabs.map((tab) => (
              <option key={tab.id} value={tab.id}>{tab.label}</option>
            ))}
          </select>
        </label>

        {/* Second level (desktop): underlined sub-tabs of the active group */}
        <nav
          aria-label={`Sections : ${activeGroup.label}`}
          className="-mb-px hidden overflow-x-auto border-b border-border sm:flex"
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
              onClick={() => selectTab(tab.id)}
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

function HeaderAvatar({ avatarUrl, initials }: { avatarUrl: string | null; initials: string }) {
  const [failed, setFailed] = useState(false);

  // Fall back to the initials on a load error (a snapshotted external avatar URL can later 404),
  // never a broken image (mirrors ProfileAvatar).
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
