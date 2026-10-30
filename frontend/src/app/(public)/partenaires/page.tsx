import { Handshake, Mail } from "lucide-react";

import { PartnerGrid } from "@/features/content/partner-card";
import { getPartners } from "@/features/content/partners-api";
import { buildPageMetadata } from "@/lib/seo";

export const metadata = buildPageMetadata({
  title: "Partenaires",
  description:
    "Les partenaires qui soutiennent l'association ArchiLAN, ses LAN Archipelago et ses runs multiworld, et comment devenir partenaire.",
  path: "/partenaires",
});

// ISR like the home page; the API drops the `partners` tag on each admin write (story 45.2).
export const revalidate = 300;

const CONTACT_EMAIL = "contact@archilan.fr";

/** Story 45.2: every partner of the association, and how to become one. */
export default async function PartenairesPage() {
  const partners = await getPartners();

  return (
    <article className="mx-auto grid max-w-content gap-12">
      <header className="grid gap-3 border-b border-border pb-8">
        <h1 className="font-heading text-4xl font-bold text-foreground">Nos partenaires</h1>
        <p className="max-w-reading text-base leading-7 text-muted-foreground">
          Ils prêtent du matériel, offrent des lots ou soutiennent l&apos;association : grâce à eux, ArchiLAN organise ses
          LAN et fait tourner ses serveurs de parties. Merci à eux.
        </p>
      </header>

      {partners.length > 0 ? (
        <PartnerGrid partners={partners} />
      ) : (
        <p className="flex items-center gap-2 rounded-xl border border-dashed border-border p-6 text-sm text-muted-foreground">
          <Handshake aria-hidden className="size-5" />
          L&apos;association n&apos;a pas encore de partenaire affiché ici.
        </p>
      )}

      <section aria-labelledby="devenir-partenaire-heading" className="grid gap-3 rounded-3xl border border-border bg-surface p-6 sm:p-10">
        <h2 className="font-heading text-2xl font-bold text-foreground" id="devenir-partenaire-heading">
          Devenir partenaire
        </h2>
        <p className="max-w-reading text-base leading-7 text-muted-foreground">
          Une boutique, une association ou une marque qui veut soutenir le jeu coopératif et les LAN à Clermont-Ferrand ?
          Écris-nous, on en parle.
        </p>
        <a
          className="inline-flex min-h-12 w-fit items-center gap-2 rounded-lg bg-accent px-5 font-semibold text-white hover:bg-accent-hover"
          href={`mailto:${CONTACT_EMAIL}`}
        >
          <Mail aria-hidden className="size-4" />
          {CONTACT_EMAIL}
        </a>
      </section>
    </article>
  );
}
