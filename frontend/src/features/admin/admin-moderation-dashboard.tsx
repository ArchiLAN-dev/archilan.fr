"use client";

import { useCallback } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useQuery } from "@tanstack/react-query";

import { DEFAULT_REPORT_FILTERS, fetchModerationQueue } from "./admin-moderation-api";
import { DEFAULT_CONTRIBUTION_FILTERS, fetchContributionQueue } from "./admin-game-contributions-api";
import { ContributionsModerationPanel } from "./contributions-moderation-panel";
import { moderationTabFromParams, TAB_PARAM, type ModerationTab } from "./moderation-filters";
import { ModerationTabs } from "./moderation-tabs";
import { ReportsModerationPanel } from "./reports-moderation-panel";

const REPORTS_COUNT_QUERY_KEY = ["admin-moderation", "pending-count"] as const;
const CONTRIBUTIONS_COUNT_QUERY_KEY = ["admin-game-contributions", "pending-count"] as const;
const STALE_TIME = 15_000;

/**
 * The moderation page. Story 39.12: the tab and the active tab's view live in the address (`?onglet=`,
 * `?statut=`, ...), so a reload, the back button or a shared link opens the same view. Switching tab starts
 * from that tab's defaults.
 */
export function AdminModerationDashboard() {
  const searchParams = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();

  const { data } = useQuery({
    queryKey: REPORTS_COUNT_QUERY_KEY,
    queryFn: () => fetchModerationQueue(DEFAULT_REPORT_FILTERS),
    staleTime: STALE_TIME,
  });
  const { data: contributions } = useQuery({
    queryKey: CONTRIBUTIONS_COUNT_QUERY_KEY,
    queryFn: () => fetchContributionQueue(DEFAULT_CONTRIBUTION_FILTERS),
    staleTime: STALE_TIME,
  });

  const params = new URLSearchParams(searchParams.toString());
  const tab = moderationTabFromParams(params);

  const go = useCallback(
    (next: URLSearchParams) => {
      const query = next.toString();
      router.replace(query === "" ? pathname : `${pathname}?${query}`, { scroll: false });
    },
    [router, pathname],
  );

  const onParams = useCallback(
    (next: URLSearchParams) => {
      if (tab === "contributions") next.set(TAB_PARAM, "contributions");
      go(next);
    },
    [go, tab],
  );

  const counts: Record<ModerationTab, number | undefined> = { reports: data?.count, contributions: contributions?.count };

  return (
    <section className="grid w-full min-w-0 grid-cols-1 gap-6 px-4 py-10">
      <header className="grid gap-1">
        <h1 className="font-heading text-2xl font-bold text-foreground">Modération</h1>
        <p className="text-sm text-muted-foreground">Signalements de commentaires et de profils, contributions aux tutoriels.</p>
      </header>

      <ModerationTabs
        counts={counts}
        onChange={(next) => go(new URLSearchParams(next === "contributions" ? `${TAB_PARAM}=contributions` : ""))}
        tab={tab}
      />

      {tab === "contributions" ? (
        <ContributionsModerationPanel onParams={onParams} params={params} />
      ) : (
        <ReportsModerationPanel onParams={onParams} params={params} />
      )}
    </section>
  );
}
