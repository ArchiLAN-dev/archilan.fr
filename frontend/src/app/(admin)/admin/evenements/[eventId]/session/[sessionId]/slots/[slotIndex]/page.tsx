import type { Metadata } from "next";
import { Suspense } from "react";

import { AdminSlotReachabilityPage } from "@/features/admin/admin-slot-reachability-page";

export const metadata: Metadata = {
  title: "Progression du slot",
};

type Props = {
  params: Promise<{ eventId: string; sessionId: string; slotIndex: string }>;
};

export default function AdminSlotReachabilityRoute({ params }: Props) {
  return (
    <Suspense>
      <AdminSlotReachabilityPage params={params} />
    </Suspense>
  );
}
