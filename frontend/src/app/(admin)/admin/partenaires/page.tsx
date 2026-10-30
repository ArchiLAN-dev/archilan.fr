import type { Metadata } from "next";

import { AdminPartners } from "@/features/admin/admin-partners";

export const metadata: Metadata = {
  title: "Partenaires",
};

export default function AdminPartenairesPage() {
  return <AdminPartners />;
}
