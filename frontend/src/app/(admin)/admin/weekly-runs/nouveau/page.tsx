import type { Metadata } from "next";

import { AdminWeeklyTemplateForm } from "@/features/admin/admin-weekly-template-form";

export const metadata: Metadata = {
  title: "Nouveau template hebdomadaire",
  description: "Créer un nouveau template de run hebdomadaire.",
  openGraph: {
    title: "Nouveau template - Administration ArchiLAN",
  },
};

export default function AdminWeeklyTemplateNewPage() {
  return <AdminWeeklyTemplateForm mode="create" />;
}
