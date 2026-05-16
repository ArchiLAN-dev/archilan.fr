"use client";

import { useEffect, useState } from "react";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { AccountRegistrations } from "./account-registrations";
import { DangerSection, PrivacySection } from "./account-profile";
import { EmailVerificationBanner } from "./email-verification-banner";
import { PersonalRunsListPage } from "@/features/personal-runs/personal-runs-list-page";
import type { Profile } from "./account-profile";

// ── Types ─────────────────────────────────────────────────────────────────────

type Tab = "inscriptions" | "parties" | "confidentialite" | "compte";

const TABS: Array<{ id: Tab; label: string; danger?: true }> = [
  { id: "inscriptions", label: "Inscriptions" },
  { id: "parties", label: "Mes parties" },
  { id: "confidentialite", label: "Confidentialité" },
  { id: "compte", label: "Compte", danger: true },
];

// ── AccountTabs ───────────────────────────────────────────────────────────────

export function AccountTabs() {
  const [activeTab, setActiveTab] = useState<Tab>("inscriptions");
  const [profile, setProfile] = useState<Profile | null>(null);
  const [loadingProfile, setLoadingProfile] = useState(true);

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

      {/* ── Tab navigation + content ─────────────────────────────────────── */}
      <div className="grid gap-6">
        <nav
          aria-label="Sections de l'espace membre"
          className="-mb-px flex overflow-x-auto border-b border-border"
          role="tablist"
        >
          {TABS.map((tab) => (
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
          {activeTab === "confidentialite" && <PrivacySection />}
          {activeTab === "compte" && <DangerSection />}
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
