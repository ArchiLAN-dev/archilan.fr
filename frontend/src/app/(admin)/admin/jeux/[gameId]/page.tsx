import type { Metadata } from "next";

import { AdminGameEditor } from "@/features/admin/admin-game-editor";

export const metadata: Metadata = {
  title: "Configurer le jeu",
};

type Props = {
  params: Promise<{ gameId: string }>;
};

export default async function AdminGameEditorPage({ params }: Props) {
  const { gameId } = await params;
  return <AdminGameEditor gameId={gameId} />;
}
