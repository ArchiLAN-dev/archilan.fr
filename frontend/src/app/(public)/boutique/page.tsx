import { buildPageMetadata } from "@/lib/seo";
import Link from "next/link";
import { RefreshCw } from "lucide-react";
import { getShopCheckoutUrl } from "@/features/payments/shop-api";
import { ShopCheckout } from "@/features/payments/shop-checkout";
import { CosmeticShop } from "@/features/wallet/shop-page";

export const metadata = buildPageMetadata({
  title: "Boutique",
  description:
    "La boutique ArchiLAN : cadres et bannières de profil à gagner en pelles, et articles officiels (sweats, stickers) via HelloAsso.",
  path: "/boutique",
});

const ASSO_TAB = "asso";

/**
 * « Boutique » (story 41.12): one shop, two tabs - the cosmetics sold for pelles (story 41.7) and the association's
 * HelloAsso shop. The tab lives in the address so each can be linked.
 */
export default async function BoutiquePage({ searchParams }: { searchParams: Promise<{ onglet?: string }> }) {
  const { onglet } = await searchParams;
  const asso = onglet === ASSO_TAB;

  return (
    <div className="mx-auto grid max-w-content gap-8">
      <header>
        <p className="mb-4 text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">Boutique</p>
        <h1 className="font-heading text-4xl font-bold leading-tight text-foreground md:text-5xl">
          {asso ? "Articles ArchiLAN" : "Cosmétiques"}
        </h1>
      </header>

      <nav aria-label="Rayons de la boutique" className="flex gap-2 border-b border-border">
        <ShopTab active={!asso} href="/boutique" label="Cosmétiques" />
        <ShopTab active={asso} href={`/boutique?onglet=${ASSO_TAB}`} label="Articles ArchiLAN" />
      </nav>

      {asso ? <AssoShop /> : <CosmeticShop />}
    </div>
  );
}

function ShopTab({ href, label, active }: { href: string; label: string; active: boolean }) {
  return (
    <Link
      aria-current={active ? "page" : undefined}
      className={`-mb-px inline-flex min-h-11 items-center border-b-2 px-3 text-sm font-semibold transition-colors ${
        active ? "border-accent text-foreground" : "border-transparent text-muted-foreground hover:text-foreground"
      }`}
      href={href}
    >
      {label}
    </Link>
  );
}

async function AssoShop() {
  const checkoutEmbedUrl = await getShopCheckoutUrl();

  return (
    <div className="grid gap-6">
      <p className="text-lg leading-8 text-muted-foreground">
        Sweats, stickers et autres produits officiels ArchiLAN. Les commandes sont gerees via HelloAsso et n&apos;incluent pas
        l&apos;inscription aux evenements.
      </p>
      {checkoutEmbedUrl ? (
        <ShopCheckout checkoutEmbedUrl={checkoutEmbedUrl} />
      ) : (
        <div className="flex items-start gap-4 card-glow rounded-lg border border-border p-6">
          <RefreshCw aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
          <div>
            <p className="font-semibold text-foreground">Boutique temporairement indisponible</p>
            <p className="mt-1 text-sm leading-6 text-muted-foreground">
              La boutique n&apos;est pas accessible pour le moment. Reessaie dans quelques instants ou contacte-nous via Discord si le
              probleme persiste.
            </p>
            <Link
              className="mt-4 inline-flex min-h-10 items-center justify-center gap-2 rounded border border-border bg-background px-3 text-sm font-semibold text-foreground hover:border-accent"
              href={`/boutique?onglet=${ASSO_TAB}`}
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
