import type { Metadata } from "next";

import { AdminWeeklyRunGameGrid } from "@/features/admin/admin-weekly-run-game-grid";

export const metadata: Metadata = {
  title: "Runs hebdomadaires",
  description: "Gestion des templates et runs hebdomadaires ArchiLAN.",
  openGraph: {
    title: "Runs hebdomadaires - Administration ArchiLAN",
  },
};

export default function AdminWeeklyRunsPage() {
  return <AdminWeeklyRunGameGrid />;
}
