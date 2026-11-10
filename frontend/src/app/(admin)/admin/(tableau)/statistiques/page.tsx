import type { Metadata } from "next";
import { Suspense } from "react";

import { AdminStatsPage } from "@/features/stats/admin-stats-page";

export const metadata: Metadata = {
  title: "Statistiques",
};

export default function StatistiquesPage() {
  // The period lives in the address (story 42.1): useSearchParams needs a Suspense boundary.
  return (
    <Suspense>
      <AdminStatsPage />
    </Suspense>
  );
}
