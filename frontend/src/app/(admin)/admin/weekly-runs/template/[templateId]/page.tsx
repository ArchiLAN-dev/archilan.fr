import type { Metadata } from "next";

import { AdminWeeklyRunTemplateDetail } from "@/features/admin/admin-weekly-run-template-detail";

export const metadata: Metadata = {
  title: "Runs du template",
  description: "Historique des runs hebdomadaires (en cours et passés) d'un template.",
  openGraph: {
    title: "Runs du template - Administration ArchiLAN",
  },
};

type Props = {
  params: Promise<{ templateId: string }>;
};

export default async function AdminWeeklyRunTemplatePage({ params }: Props) {
  const { templateId } = await params;
  return <AdminWeeklyRunTemplateDetail templateId={templateId} />;
}
