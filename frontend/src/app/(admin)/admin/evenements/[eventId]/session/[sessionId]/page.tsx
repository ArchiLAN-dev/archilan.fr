import type { Metadata } from "next";
import { Suspense } from "react";

import { AdminSessionDetailPage } from "@/features/admin/admin-session-page";

export const metadata: Metadata = {
  title: "Détail session",
};

type Props = {
  params: Promise<{ eventId: string; sessionId: string }>;
};

export default function AdminSessionDetailRoute({ params }: Props) {
  return (
    <Suspense>
      <AdminSessionDetailPage params={params} />
    </Suspense>
  );
}
