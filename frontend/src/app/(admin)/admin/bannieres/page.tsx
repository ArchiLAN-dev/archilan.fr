import type { Metadata } from "next";

import { AdminProfileBannersPage } from "@/features/admin/admin-profile-banners";

export const metadata: Metadata = {
  title: "Bannières",
};

export default function AdminBannieresPage() {
  return <AdminProfileBannersPage />;
}
