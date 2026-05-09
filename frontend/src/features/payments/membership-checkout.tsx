"use client";

import { UserPlus } from "lucide-react";

import { CgvAcceptanceGate } from "@/features/payments/cgv-acceptance-gate";
import { HelloAssoIframe } from "@/features/payments/helloasso-iframe";

export function MembershipCheckout({ checkoutEmbedUrl }: { checkoutEmbedUrl: string }) {
  return (
    <div className="card-glow rounded-lg border border-border p-6">
      <div className="flex items-center gap-3">
        <UserPlus aria-hidden="true" className="size-5 text-accent-warm" />
        <h2 className="font-heading text-2xl font-semibold text-foreground">
          Payer la cotisation
        </h2>
      </div>

      <p className="mt-4 text-sm leading-6 text-muted-foreground">
        Le paiement de la cotisation est gere via HelloAsso. Accepte les conditions ci-dessous pour
        acceder au formulaire.
      </p>

      <div className="mt-3 rounded border border-accent/30 bg-accent/5 px-4 py-3 text-sm text-foreground">
        <strong>Note :</strong> Le paiement de la cotisation ne suffit pas a t&apos;octroyer
        automatiquement le statut de membre. Un administrateur validera ton adhesion apres reception
        du paiement.
      </div>

      <CgvAcceptanceGate actionLabel="Afficher le formulaire de cotisation" includeCgu>
        <HelloAssoIframe src={checkoutEmbedUrl} title="Formulaire de cotisation HelloAsso" />
      </CgvAcceptanceGate>
    </div>
  );
}
