import type { Metadata } from "next";

import { BalancePanel } from "@/features/personal-runs/balance-report";

export const metadata: Metadata = {
  title: "Équilibre de l'événement",
};

type Props = {
  params: Promise<{ eventId: string }>;
};

/** Story 56.3: the event's dry generation and its balance report, before the day. */
export default async function EventBalancePage({ params }: Props) {
  const { eventId } = await params;

  return (
    <section className="grid min-w-0 gap-4">
      <header className="grid gap-1">
        <h1 className="font-heading text-lg font-semibold text-foreground">Équilibre</h1>
        <p className="max-w-2xl text-sm text-muted-foreground">
          Génère la partie à blanc avec les inscriptions et les YAML du moment, pour repérer un monde trop long ou
          qui garde les clés des autres. Rien n&apos;est lancé et les joueurs ne voient que leur ligne.
        </p>
      </header>
      <BalancePanel canGenerate scope={{ kind: "event", eventId }} />
    </section>
  );
}
