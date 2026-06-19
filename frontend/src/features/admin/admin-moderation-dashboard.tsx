"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";

import { DEFAULT_REPORT_FILTERS, fetchModerationQueue } from "./admin-moderation-api";
import { ContributionsModerationPanel } from "./contributions-moderation-panel";
import { ReportsModerationPanel } from "./reports-moderation-panel";

const COUNT_QUERY_KEY = ["admin-moderation", "pending-count"] as const;
const STALE_TIME = 15_000;

type ModerationTab = "reports" | "contributions";

export function AdminModerationDashboard() {
  const { data } = useQuery({
    queryKey: COUNT_QUERY_KEY,
    queryFn: () => fetchModerationQueue(DEFAULT_REPORT_FILTERS),
    staleTime: STALE_TIME,
  });
  const [tab, setTab] = useState<ModerationTab>("reports");

  return (
    <section className="grid w-full min-w-0 grid-cols-1 gap-6 px-4 py-10">
      <header className="grid gap-1">
        <h1 className="font-heading text-2xl font-bold text-foreground">Modération</h1>
        <p className="text-sm text-muted-foreground">Signalements de commentaires et contributions aux tutoriels.</p>
      </header>

      <div className="flex flex-wrap gap-2 border-b border-border" role="tablist">
        <button
          aria-selected={tab === "reports"}
          className={`-mb-px min-h-10 border-b-2 px-4 text-sm font-semibold transition-colors ${tab === "reports" ? "border-accent text-foreground" : "border-transparent text-muted-foreground hover:text-foreground"}`}
          onClick={() => setTab("reports")}
          role="tab"
          type="button"
        >
          Signalements{data ? ` · ${data.count}` : ""}
        </button>
        <button
          aria-selected={tab === "contributions"}
          className={`-mb-px min-h-10 border-b-2 px-4 text-sm font-semibold transition-colors ${tab === "contributions" ? "border-accent text-foreground" : "border-transparent text-muted-foreground hover:text-foreground"}`}
          onClick={() => setTab("contributions")}
          role="tab"
          type="button"
        >
          Contributions tutoriels
        </button>
      </div>

      {tab === "contributions" ? <ContributionsModerationPanel /> : <ReportsModerationPanel />}
    </section>
  );
}
