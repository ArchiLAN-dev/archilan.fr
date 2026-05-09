"use client";

import { ShoppingCart } from "lucide-react";

import { CgvAcceptanceGate } from "@/features/payments/cgv-acceptance-gate";
import { HelloAssoIframe } from "@/features/payments/helloasso-iframe";

export function EventCheckout({ checkoutEmbedUrl }: { checkoutEmbedUrl: string }) {
  return (
    <section className="rounded-lg border border-border p-6" id="billetterie">
      <div className="flex items-center gap-3">
        <ShoppingCart aria-hidden="true" className="size-5 text-accent-warm" />
        <h2 className="font-heading text-2xl font-semibold text-foreground">Inscription en ligne</h2>
      </div>

      <p className="mt-4 text-sm leading-6 text-muted-foreground">
        Les inscriptions sont gerees via HelloAsso. Accepte les conditions ci-dessous pour acceder au
        formulaire d&apos;inscription.
      </p>

      <CgvAcceptanceGate actionLabel="Afficher le formulaire d'inscription">
        <HelloAssoIframe src={checkoutEmbedUrl} title="Formulaire d'inscription HelloAsso" />
      </CgvAcceptanceGate>
    </section>
  );
}
