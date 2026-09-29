import type { Metadata } from "next";
import { Suspense } from "react";

import { AdminModerationDashboard } from "@/features/admin/admin-moderation-dashboard";

export const metadata: Metadata = {
  title: "Modération",
};

export default function AdminModerationPage() {
  // The dashboard reads its view from the address (story 39.12): useSearchParams needs a Suspense boundary.
  return (
    <Suspense>
      <AdminModerationDashboard />
    </Suspense>
  );
}
