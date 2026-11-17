import type { Metadata } from "next";

import { AdminLinks } from "@/features/admin/admin-links";

export const metadata: Metadata = {
  title: "Liens suivis",
};

export default function AdminLiensPage() {
  return <AdminLinks />;
}
