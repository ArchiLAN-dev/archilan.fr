import type { Metadata } from "next";
import { Suspense } from "react";

import { ContributionDetailPage } from "@/features/admin/contribution-detail-page";

export const metadata: Metadata = {
  title: "Contribution tutoriel",
};

type Props = {
  params: Promise<{ id: string }>;
};

export default async function ContributionPage({ params }: Props) {
  const { id } = await params;

  // The page reads the list's view from the address to go back to it (story 39.16): useSearchParams needs Suspense.
  return (
    <Suspense>
      <ContributionDetailPage id={id} />
    </Suspense>
  );
}
