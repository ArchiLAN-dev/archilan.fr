import type { Metadata } from "next";

import { AdminAvatarFramesPage } from "@/features/admin/admin-avatar-frames";

export const metadata: Metadata = {
  title: "Cadres",
};

export default function AdminCadresPage() {
  return <AdminAvatarFramesPage />;
}
