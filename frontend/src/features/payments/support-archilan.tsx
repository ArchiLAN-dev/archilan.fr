import Link from "next/link";
import type { ReactNode } from "react";
import { AlertCircle, HeartHandshake, ShoppingBag, UserPlus } from "lucide-react";

import { DonationCheckout } from "./donation-checkout";
import { MembershipCheckout } from "./membership-checkout";
import { ShopAnnouncementBanner } from "./shop-announcement-banner";
import { ShopCheckout } from "./shop-checkout";

export type SupportForms = { membership: string | null; donation: string | null; shop: string | null };

export const SUPPORT_TAB_HREF = "/boutique?onglet=asso";

const SECTIONS = [
  { id: "adhesion", label: "Adhérer", icon: UserPlus },
  { id: "don", label: "Faire un don", icon: HeartHandshake },
  { id: "articles", label: "Articles ArchiLAN", icon: ShoppingBag },
] as const;

/**
 * « Soutenir ArchiLAN » (story 41.13): everything that helps the association, in one tab of the shop - joining,
 * donating, buying an official item. Each block embeds its HelloAsso form, or says it is not open yet.
 */
export function SupportArchilan({ forms }: { forms: SupportForms }) {
  return (
    <div className="grid gap-10">
      <p className="max-w-2xl text-lg leading-8 text-muted-foreground">
        ArchiLAN est une association : les événements, les serveurs et le site tiennent grâce à ses membres et à leurs dons.
      </p>

      <nav aria-label="Sections" className="grid gap-3 sm:grid-cols-3">
        {SECTIONS.map(({ id, label, icon: Icon }) => (
          <a className="card-glow flex items-center gap-3 rounded-lg border border-border p-4 font-semibold text-foreground transition-colors hover:border-accent" href={`#${id}`} key={id}>
            <Icon aria-hidden="true" className="size-5 text-accent-warm" />
            {label}
          </a>
        ))}
      </nav>

      <SupportSection id="adhesion" title="Adhérer">
        <p className="text-muted-foreground">
          Deviens membre de l&apos;association et soutiens l&apos;organisation des événements Archipelago. La cotisation annuelle
          est fixée par le bureau de l&apos;association.
        </p>
        {forms.membership ? <MembershipCheckout checkoutEmbedUrl={forms.membership} /> : <Unavailable what="Le formulaire de cotisation" />}
      </SupportSection>

      <SupportSection id="don" title="Faire un don">
        {forms.donation ? <DonationCheckout checkoutEmbedUrl={forms.donation} /> : <Unavailable what="Le formulaire de don" />}
      </SupportSection>

      <SupportSection id="articles" title="Articles ArchiLAN">
        <ShopAnnouncementBanner />
        <p className="text-muted-foreground">
          Sweats, stickers et autres produits officiels. Les commandes passent par HelloAsso et n&apos;incluent pas
          l&apos;inscription aux événements.
        </p>
        {forms.shop ? <ShopCheckout checkoutEmbedUrl={forms.shop} /> : <Unavailable what="La boutique d'articles" />}
      </SupportSection>
    </div>
  );
}

function SupportSection({ id, title, children }: { id: string; title: string; children: ReactNode }) {
  return (
    <section aria-labelledby={`${id}-title`} className="grid scroll-mt-24 gap-4" id={id}>
      <h2 className="font-heading text-3xl font-bold text-foreground" id={`${id}-title`}>
        {title}
      </h2>
      {children}
    </section>
  );
}

function Unavailable({ what }: { what: string }) {
  return (
    <div className="flex items-start gap-4 rounded-lg border border-border p-6">
      <AlertCircle aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
      <div>
        <p className="font-semibold text-foreground">{what} n&apos;est pas disponible pour le moment.</p>
        <p className="mt-1 text-sm leading-6 text-muted-foreground">
          Reviens bientôt, ou contacte-nous via Discord.{" "}
          <Link className="font-semibold text-foreground underline underline-offset-2" href={SUPPORT_TAB_HREF}>
            Réessayer
          </Link>
        </p>
      </div>
    </div>
  );
}
