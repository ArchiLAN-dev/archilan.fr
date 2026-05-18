import { Suspense } from "react";
import { Loader2 } from "lucide-react";
import type { Metadata } from "next";

import { WeeklyRunSlotPage } from "@/features/weekly-runs/weekly-run-slot-page";

export const metadata: Metadata = {
  title: "Ma progression - Run hebdo",
  description: "Suis ta progression sur le run hebdomadaire Archipelago.",
  openGraph: {
    title: "Ma progression - Run hebdomadaire",
  },
};

type Props = {
  params: Promise<{ weeklyRunId: string }>;
};

export default function WeeklyRunSlotRoute({ params }: Props) {
  return (
    <Suspense
      fallback={
        <div className="mx-auto max-w-sm py-20 text-center">
          <Loader2 aria-hidden className="mx-auto size-8 animate-spin text-muted-foreground/40" />
          <p className="mt-4 text-sm text-muted-foreground">Chargement…</p>
        </div>
      }
    >
      <WeeklyRunSlotPage params={params} />
    </Suspense>
  );
}
