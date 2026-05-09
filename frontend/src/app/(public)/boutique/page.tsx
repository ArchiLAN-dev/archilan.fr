import type { Metadata } from "next";
import Link from "next/link";
import { RefreshCw } from "lucide-react";
import { getShopCheckoutUrl } from "@/features/payments/shop-api";
import { ShopCheckout } from "@/features/payments/shop-checkout";

export const metadata: Metadata = {
  title: "Boutique",
  description:
    "Commande des articles ArchiLAN via HelloAsso - sweats, stickers, et autres produits officiels.",
};

export default async function BoutiquePage() {
  const checkoutEmbedUrl = await getShopCheckoutUrl();

  return (
    <div className="mx-auto grid max-w-3xl gap-8">
      <header>
        <p className="mb-4 text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">
          Boutique
        </p>
        <h1 className="font-heading text-4xl font-bold leading-tight text-foreground md:text-5xl">
          Articles ArchiLAN
        </h1>
        <p className="mt-5 text-lg leading-8 text-muted-foreground">
          Sweats, stickers et autres produits officiels ArchiLAN. Les commandes sont gerees via
          HelloAsso et n&apos;incluent pas l&apos;inscription aux evenements.
        </p>
      </header>

      {checkoutEmbedUrl ? (
        <ShopCheckout checkoutEmbedUrl={checkoutEmbedUrl} />
      ) : (
        <div className="flex items-start gap-4 card-glow rounded-lg border border-border p-6">
          <RefreshCw
            aria-hidden="true"
            className="mt-0.5 size-5 shrink-0 text-muted-foreground"
          />
          <div>
            <p className="font-semibold text-foreground">Boutique temporairement indisponible</p>
            <p className="mt-1 text-sm leading-6 text-muted-foreground">
              La boutique n&apos;est pas accessible pour le moment. Reessaie dans quelques instants ou
              contacte-nous via Discord si le probleme persiste.
            </p>
            <Link
              className="mt-4 inline-flex min-h-10 items-center justify-center gap-2 rounded border border-border bg-background px-3 text-sm font-semibold text-foreground hover:border-accent"
              href="/boutique"
            >
              <RefreshCw aria-hidden="true" className="size-4" />
              Reessayer
            </Link>
          </div>
        </div>
      )}
    </div>
  );
}
