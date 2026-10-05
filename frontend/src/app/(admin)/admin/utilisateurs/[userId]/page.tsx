import type { Metadata } from "next";
import { Suspense } from "react";
import { AdminUserDetailPage } from "@/features/admin/admin-user-detail";

export const metadata: Metadata = {
  title: "Fiche utilisateur",
};

type Props = {
  params: Promise<{ userId: string }>;
};

export default async function AdminUserPage({ params }: Props) {
  const { userId } = await params;

  return (
    // The sheet reads its open tab from the address (story 36.8): useSearchParams needs a Suspense boundary.
    <Suspense>
      <AdminUserDetailPage userId={userId} />
    </Suspense>
  );
}
