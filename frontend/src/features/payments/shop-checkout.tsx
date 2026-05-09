"use client";

import { ShoppingBag } from "lucide-react";

import { CgvAcceptanceGate } from "@/features/payments/cgv-acceptance-gate";
import { HelloAssoIframe } from "@/features/payments/helloasso-iframe";

export function ShopCheckout({ checkoutEmbedUrl }: { checkoutEmbedUrl: string }) {
  return (
    <div className="card-glow rounded-lg border border-border p-6">
      <div className="flex items-center gap-3">
        <ShoppingBag aria-hidden="true" className="size-5 text-accent-warm" />
        <h2 className="font-heading text-2xl font-semibold text-foreground">Commander</h2>
      </div>

      <p className="mt-4 text-sm leading-6 text-muted-foreground">
        Les commandes sont traitees via HelloAsso. Accepte les conditions ci-dessous pour acceder au
        formulaire de commande.
      </p>

      <div className="mt-3 rounded border border-border bg-background px-4 py-3 text-sm text-muted-foreground">
        La boutique ArchiLAN est distincte des inscriptions aux evenements. Un article achete ici ne
        constitue pas une inscription a un evenement.
      </div>

      <CgvAcceptanceGate actionLabel="Afficher le formulaire de commande">
        <HelloAssoIframe src={checkoutEmbedUrl} title="Boutique ArchiLAN - HelloAsso" />
      </CgvAcceptanceGate>
    </div>
  );
}
