import type { Metadata } from "next";
import { Suspense } from "react";

import { AdminEventPellesPage } from "@/features/wallet/admin-event-pelles-page";

export const metadata: Metadata = {
  title: "Pelles de l'événement",
};

type Props = {
  params: Promise<{ eventId: string }>;
};

export default function EventPellesPage({ params }: Props) {
  return (
    // The page reads its open tab from the address (story 41.57): useSearchParams needs a Suspense boundary.
    <Suspense>
      <AdminEventPellesPage params={params} />
    </Suspense>
  );
}
