import { Suspense } from "react";
import type { Metadata } from "next";

import { EventSlotDetailPage } from "@/features/personal-runs/personal-run-slot-detail-page";

export const metadata: Metadata = {
  title: "Progression du slot",
  robots: { index: false, follow: false },
};

type Props = {
  params: Promise<{ eventSlug: string; registrationId: string; slotIndex: string }>;
};

/** Story 41.9: an event player's own slot - checks, items, hints, and hints bought with the event's pelles. */
export default function EventSlotProgressionRoute({ params }: Props) {
  return (
    <Suspense>
      <EventSlotDetailPage params={params} />
    </Suspense>
  );
}
