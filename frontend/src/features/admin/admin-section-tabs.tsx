"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { TAB_LIST_CLASS, tabClassName } from "@/components/ui/tabs";
import { APWORLD_INCIDENT_SUMMARY_QUERY_KEY, fetchApworldIncidentSummary } from "./admin-apworld-health-api";
import { DEFAULT_CONTRIBUTION_FILTERS, fetchContributionQueue } from "./admin-game-contributions-api";
import { DEFAULT_REPORT_FILTERS, fetchModerationQueue } from "./admin-moderation-api";
import { ADMIN_SECTIONS, isPathActive, type AdminBadges, type AdminSection, type AdminSectionKey } from "./admin-sections";

/**
 * What waits for someone, keyed by page address: apworld incidents nobody has taken (story 38.3) and each moderation
 * queue (story 39.13). Same queries, hence same cache, as their pages.
 */
export function useAdminBadges(enabled: boolean): AdminBadges {
  const { data: apworldSummary } = useQuery({
    queryKey: APWORLD_INCIDENT_SUMMARY_QUERY_KEY,
    queryFn: fetchApworldIncidentSummary,
    enabled,
    staleTime: 60_000,
  });
  const { data: pendingReports } = useQuery({
    queryKey: ["admin-moderation", "pending-count"],
    queryFn: () => fetchModerationQueue(DEFAULT_REPORT_FILTERS),
    enabled,
    staleTime: 60_000,
  });
  const { data: pendingContributions } = useQuery({
    queryKey: ["admin-game-contributions", "pending-count"],
    queryFn: () => fetchContributionQueue(DEFAULT_CONTRIBUTION_FILTERS),
    enabled,
    staleTime: 60_000,
  });
  return {
    "/admin/sante-apworlds": apworldSummary?.unacknowledged ?? 0,
    "/admin/moderation/signalements": pendingReports?.count ?? 0,
    "/admin/moderation/contributions": pendingContributions?.count ?? 0,
  };
}

type ViewProps = { section: AdminSection; pathname: string; badges: AdminBadges };

/** Story 3.20: the tabs of a grouped menu entry, the site's tab style, above the page. */
export function AdminSectionTabsView({ section, pathname, badges }: ViewProps) {
  if (!section.tabs) return null;
  return (
    <nav aria-label={section.label} className="px-4 pt-4 md:px-6">
      <ul className={TAB_LIST_CLASS}>
        {section.tabs.map((tab) => {
          const current = isPathActive(pathname, tab.href, tab.exact);
          const count = badges[tab.href] ?? 0;
          return (
            <li key={tab.href}>
              <Link aria-current={current ? "page" : undefined} className={tabClassName(current)} href={tab.href}>
                {tab.label}
                {count > 0 ? (
                  <span
                    aria-label={`${count} à prendre en charge`}
                    className="inline-flex min-w-5 items-center justify-center rounded-full bg-danger px-1.5 text-[11px] font-bold text-white"
                  >
                    {count}
                  </span>
                ) : null}
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}

/** The tabs of one section, for the layout of its route group. The shell has already checked the admin role. */
export function AdminSectionTabs({ section }: { section: AdminSectionKey }) {
  const pathname = usePathname();
  const badges = useAdminBadges(true);
  return <AdminSectionTabsView badges={badges} pathname={pathname} section={ADMIN_SECTIONS[section]} />;
}
