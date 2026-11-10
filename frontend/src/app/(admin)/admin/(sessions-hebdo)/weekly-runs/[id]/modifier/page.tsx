import type { Metadata } from "next";

import { AdminWeeklyTemplateForm } from "@/features/admin/admin-weekly-template-form";

export const metadata: Metadata = {
  title: "Modifier le template",
  description: "Modifier un template de run hebdomadaire.",
  openGraph: {
    title: "Modifier le template - Administration ArchiLAN",
  },
};

type Props = {
  params: Promise<{ id: string }>;
};

export default async function AdminWeeklyTemplateEditPage({ params }: Props) {
  const { id } = await params;
  return <AdminWeeklyTemplateForm mode="edit" templateId={id} />;
}
