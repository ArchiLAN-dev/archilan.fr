"use client";

import { Info } from "lucide-react";
import { CgvAcceptanceGate } from "@/features/payments/cgv-acceptance-gate";
import { HelloAssoIframe } from "@/features/payments/helloasso-iframe";

export function MembershipCheckout({ checkoutEmbedUrl }: { checkoutEmbedUrl: string }) {
  return (
    <div className="grid gap-6">
      <div className="card-glow rounded-lg border border-border p-6">
        <h2 className="font-heading text-2xl font-semibold text-foreground">
          Payer la cotisation
        </h2>
        <p className="mt-2 text-sm leading-6 text-muted-foreground">
          Le paiement est géré via HelloAsso. Accepte les conditions ci-dessous pour accéder au
          formulaire.
        </p>

        <div className="mt-5 flex items-start gap-3 rounded-md border border-border bg-surface px-4 py-3">
          <Info aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
          <p className="text-sm leading-6 text-muted-foreground">
            Le paiement seul ne suffit pas à valider ton adhésion. Un administrateur confirmera
            ton statut de membre après réception du paiement.
          </p>
        </div>

        <CgvAcceptanceGate actionLabel="Accéder au formulaire" includeCgu>
          <HelloAssoIframe src={checkoutEmbedUrl} title="Formulaire de cotisation HelloAsso" />
        </CgvAcceptanceGate>
      </div>
    </div>
  );
}
