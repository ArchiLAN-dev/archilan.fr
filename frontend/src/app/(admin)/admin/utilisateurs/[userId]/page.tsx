import type { Metadata } from "next";
import { AdminUserDetailPage } from "@/features/admin/admin-user-detail";

export const metadata: Metadata = {
  title: "Fiche utilisateur",
};

type Props = {
  params: Promise<{ userId: string }>;
};

export default async function AdminUserPage({ params }: Props) {
  const { userId } = await params;

  return <AdminUserDetailPage userId={userId} />;
}
