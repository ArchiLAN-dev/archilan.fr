import type { Metadata } from "next";
import { Suspense } from "react";

import { AdminContributionsPage } from "@/features/admin/admin-contributions-page";

export const metadata: Metadata = {
  title: "Contributions tutoriels",
};

export default function ContributionsPage() {
  // The page reads its view from the address (story 39.12): useSearchParams needs a Suspense boundary.
  return (
    <Suspense>
      <AdminContributionsPage />
    </Suspense>
  );
}
