"use client";

import { HeartHandshake } from "lucide-react";

import { CgvAcceptanceGate } from "@/features/payments/cgv-acceptance-gate";
import { HelloAssoIframe } from "@/features/payments/helloasso-iframe";

/** Story 41.13: a donation to the association, through HelloAsso. */
export function DonationCheckout({ checkoutEmbedUrl }: { checkoutEmbedUrl: string }) {
  return (
    <div className="card-glow rounded-lg border border-border p-6">
      <div className="flex items-center gap-3">
        <HeartHandshake aria-hidden="true" className="size-5 text-accent-warm" />
        <h3 className="font-heading text-2xl font-semibold text-foreground">Faire un don</h3>
      </div>

      <p className="mt-4 text-sm leading-6 text-muted-foreground">
        Un don, du montant de ton choix, finance les serveurs, le matériel et les événements. Le paiement passe par HelloAsso.
        Accepte les conditions ci-dessous pour accéder au formulaire.
      </p>

      <CgvAcceptanceGate actionLabel="Accéder au formulaire de don">
        <HelloAssoIframe src={checkoutEmbedUrl} title="Don à ArchiLAN - HelloAsso" />
      </CgvAcceptanceGate>
    </div>
  );
}
