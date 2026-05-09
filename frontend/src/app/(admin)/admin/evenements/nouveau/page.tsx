import type { Metadata } from "next";

import { AdminEventCreatePage } from "@/features/admin/admin-event-create-page";

export const metadata: Metadata = { title: "Nouvel événement" };

export default function Page() {
  return <AdminEventCreatePage />;
}
