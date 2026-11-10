import type { Metadata } from "next";

import { AdminProfileTitlesPage } from "@/features/admin/admin-profile-titles";

export const metadata: Metadata = {
  title: "Titres",
};

export default function AdminTitresPage() {
  return <AdminProfileTitlesPage />;
}
