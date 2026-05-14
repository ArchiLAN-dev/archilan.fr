import { Suspense } from "react";
import type { Metadata } from "next";

import { PersonalRunSlotDetailPage } from "@/features/personal-runs/personal-run-slot-detail-page";

export const metadata: Metadata = {
  title: "Progression du slot",
};

type Props = {
  params: Promise<{ runId: string; slotIndex: string }>;
};

export default function RunSlotProgressionRoute({ params }: Props) {
  return (
    <Suspense>
      <PersonalRunSlotDetailPage params={params} />
    </Suspense>
  );
}
