"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";

import { useAuth } from "@/features/auth/auth-context";
import { getAccountMembership } from "@/features/payments/membership-api";
import { fetchCurrentWeeklyRuns } from "./weekly-runs-api";
import { WeeklyRunCard } from "./weekly-run-card";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";

export function WeeklyRunsClientPage() {
  const { user, loading } = useAuth();
  const router = useRouter();

  const isMember =
    user?.roles.includes("ROLE_MEMBER") === true ||
    user?.roles.includes("ROLE_ADMIN") === true;
  const isAdmin = user?.roles.includes("ROLE_ADMIN") === true;

  const { data: membership, isLoading: membershipLoading } = useQuery({
    queryKey: ["account-membership"],
    queryFn: getAccountMembership,
    staleTime: DEFAULT_STALE_TIME,
    enabled: Boolean(user) && !isAdmin && !isMember,
  });

  const hasActiveMembership = membership?.status === "active";
  const canAccessWeeklyRuns = isAdmin || isMember || hasActiveMembership;

  useEffect(() => {
    if (!loading && !user) {
      router.push(`/connexion?returnTo=${encodeURIComponent("/runs-hebdo")}`);
    }
  }, [loading, user, router]);

  const { data: runs = [] } = useQuery({
    queryKey: ["weekly-runs", "current"],
    queryFn: fetchCurrentWeeklyRuns,
    staleTime: DEFAULT_STALE_TIME,
    refetchInterval: 60_000,
    enabled: canAccessWeeklyRuns,
  });

  if (loading || (user && !isAdmin && !isMember && membershipLoading)) {
    return (
      <div className="flex min-h-[40vh] items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-border border-t-accent" />
      </div>
    );
  }

  if (!user) {
    return null;
  }

  if (!canAccessWeeklyRuns) {
    return (
      <div className="py-16 text-center">
        <p className="text-foreground">
          Cette section est réservée aux membres ArchiLAN.
        </p>
        <p className="mt-2 text-sm text-muted-foreground">
          Adhère à l&apos;association pour participer aux runs hebdomadaires.
        </p>
      </div>
    );
  }

  if (runs.length === 0) {
    return (
      <div className="py-16 text-center">
        <p className="text-lg font-semibold text-foreground">
          Aucun run cette semaine - revenez lundi&nbsp;!
        </p>
        <p className="mt-2 text-sm text-muted-foreground">
          Les runs hebdomadaires démarrent automatiquement chaque lundi.
        </p>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-6">
      {runs.map((run) => (
        <WeeklyRunCard key={run.weeklyRunId} myUserId={user.id} run={run} />
      ))}
    </div>
  );
}
