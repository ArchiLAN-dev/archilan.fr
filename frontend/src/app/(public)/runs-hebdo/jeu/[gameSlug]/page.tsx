import type { Metadata } from "next";
import { WeeklyRunGameClientPage } from "@/features/weekly-runs/weekly-run-game-client";
import { buildPageMetadata } from "@/lib/seo";

type Props = {
  params: Promise<{ gameSlug: string }>;
};

// generateMetadata (not a static const) so the canonical carries the visited gameSlug.
// The generic title stays for now; per-game copy is 34.6's scope.
export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { gameSlug } = await params;
  return buildPageMetadata({
    title: "Runs hebdomadaires",
    description: "Consulte les runs hebdomadaires pour ce jeu et compare ton temps avec les autres membres.",
    path: `/runs-hebdo/jeu/${gameSlug}`,
  });
}

export default function WeeklyRunGamePage({ params }: Props) {
  return (
    <div className="mx-auto max-w-2xl">
      <WeeklyRunGameClientPage params={params} />
    </div>
  );
}
