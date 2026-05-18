import type { Metadata } from "next";

import { AdminWeeklyRunsDashboard } from "@/features/admin/admin-weekly-runs-dashboard";

export const metadata: Metadata = {
  title: "Runs hebdomadaires",
  description: "Gestion des templates et runs hebdomadaires ArchiLAN.",
  openGraph: {
    title: "Runs hebdomadaires - Administration ArchiLAN",
  },
};

export default function AdminWeeklyRunsPage() {
  return <AdminWeeklyRunsDashboard />;
}
