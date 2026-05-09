import type { Metadata } from "next";

import { AdminSessionPage } from "@/features/admin/admin-session-page";

export const metadata: Metadata = {
  title: "Sessions",
};

type Props = {
  params: Promise<{ eventId: string }>;
};

export default function AdminSessionPageRoute({ params }: Props) {
  return <AdminSessionPage params={params} />;
}
