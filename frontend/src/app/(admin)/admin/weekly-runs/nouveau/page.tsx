import type { Metadata } from "next";

import { AdminWeeklyTemplateForm } from "@/features/admin/admin-weekly-template-form";

export const metadata: Metadata = {
  title: "Nouveau template hebdomadaire",
  description: "Créer un nouveau template de run hebdomadaire.",
  openGraph: {
    title: "Nouveau template - Administration ArchiLAN",
  },
};

type Props = {
  searchParams: Promise<{ gameId?: string }>;
};

export default async function AdminWeeklyTemplateNewPage({ searchParams }: Props) {
  const { gameId } = await searchParams;
  return <AdminWeeklyTemplateForm initialGameId={gameId} mode="create" />;
}
