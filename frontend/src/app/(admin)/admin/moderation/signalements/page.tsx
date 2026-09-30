import type { Metadata } from "next";
import { Suspense } from "react";

import { AdminReportsPage } from "@/features/admin/admin-reports-page";

export const metadata: Metadata = {
  title: "Signalements",
};

export default function SignalementsPage() {
  // The page reads its view from the address (story 39.12): useSearchParams needs a Suspense boundary.
  return (
    <Suspense>
      <AdminReportsPage />
    </Suspense>
  );
}
