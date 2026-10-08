import { buildPageMetadata } from "@/lib/seo";
import Link from "next/link";
import { getDonationCheckoutUrl } from "@/features/payments/donation-api";
import { getMembershipCheckoutUrl } from "@/features/payments/membership-api";
import { getShopCheckoutUrl } from "@/features/payments/shop-api";
import { SupportArchilan } from "@/features/payments/support-archilan";
import { CosmeticShop } from "@/features/wallet/shop-page";
import { shelfFromParam } from "@/features/wallet/shop-shelves";

export const metadata = buildPageMetadata({
  title: "Boutique",
  description:
    "La boutique ArchiLAN : cadres et bannières de profil à gagner en pelles, et de quoi soutenir l'association - adhésion, don, articles officiels via HelloAsso.",
  path: "/boutique",
});

const ASSO_TAB = "asso";

/**
 * « Boutique » (story 41.12): one shop, two tabs - the cosmetics sold for pelles (story 41.7) and, story 41.13,
 * « Soutenir ArchiLAN »: membership, donation and official items through HelloAsso. The tab lives in the address so
 * each can be linked.
 */
export default async function BoutiquePage({ searchParams }: { searchParams: Promise<{ onglet?: string; rayon?: string }> }) {
  const { onglet, rayon } = await searchParams;
  const asso = onglet === ASSO_TAB;

  return (
    <div className="mx-auto grid max-w-content gap-8">
      <header>
        <p className="mb-4 text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">Boutique</p>
        <h1 className="font-heading text-4xl font-bold leading-tight text-foreground md:text-5xl">
          {asso ? "Soutenir ArchiLAN" : "Cosmétiques"}
        </h1>
      </header>

      <nav aria-label="Rayons de la boutique" className="flex gap-2 border-b border-border">
        <ShopTab active={!asso} href="/boutique" label="Cosmétiques" />
        <ShopTab active={asso} href={`/boutique?onglet=${ASSO_TAB}`} label="Soutenir ArchiLAN" />
      </nav>

      {asso ? <SupportTab /> : <CosmeticShop shelf={shelfFromParam(rayon)} />}
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

async function SupportTab() {
  const [membership, donation, shop] = await Promise.all([getMembershipCheckoutUrl(), getDonationCheckoutUrl(), getShopCheckoutUrl()]);
  return <SupportArchilan forms={{ membership, donation, shop }} />;
}
