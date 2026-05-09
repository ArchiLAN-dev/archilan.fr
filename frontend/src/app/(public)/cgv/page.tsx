import type { Metadata } from "next";
import { LegalPlaceholder } from "@/components/legal-placeholder";

export const metadata: Metadata = {
  title: "Conditions Générales de Vente",
  description:
    "Conditions générales de vente applicables aux achats et cotisations ArchiLAN.",
  robots: { index: true, follow: true },
};

export default function CgvPage() {
  return (
    <article className="mx-auto grid max-w-3xl gap-10">
      <header className="grid gap-2 border-b border-border pb-8">
        <h1 className="font-heading text-4xl font-bold text-foreground">
          Conditions Générales de Vente
        </h1>
        <p className="text-sm text-muted-foreground">
          Version du 2 mai 2026 - contenu à compléter avant publication.
        </p>
      </header>

      <section aria-labelledby="objet-cgv-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="objet-cgv-heading"
        >
          Objet
        </h2>
        <p className="text-sm leading-7 text-muted-foreground">
          Les présentes CGV régissent les achats de billets d&apos;événements, les cotisations
          d&apos;adhésion et les achats en boutique réalisés via le site archilan.fr. Les
          transactions sont traitées par HelloAsso, prestataire de paiement tiers.
        </p>
      </section>

      <section aria-labelledby="prix-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="prix-heading"
        >
          Tarifs
        </h2>
        <LegalPlaceholder>
          Politique tarifaire : prix TTC, devise, mention des contributions HelloAsso laissées à
          la discrétion de l&apos;acheteur.
        </LegalPlaceholder>
      </section>

      <section aria-labelledby="commande-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="commande-heading"
        >
          Commande et paiement
        </h2>
        <LegalPlaceholder>
          Processus de commande, moyens de paiement acceptés via HelloAsso, confirmation de
          transaction et envoi du justificatif.
        </LegalPlaceholder>
      </section>

      <section aria-labelledby="remboursement-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="remboursement-heading"
        >
          Remboursement et annulation
        </h2>
        <LegalPlaceholder>
          Conditions de remboursement en cas d&apos;annulation d&apos;événement par
          l&apos;association. Politique d&apos;annulation à l&apos;initiative de l&apos;acheteur.
          Droit de rétractation si applicable (préciser l&apos;applicabilité pour une association
          loi 1901).
        </LegalPlaceholder>
      </section>

      <section aria-labelledby="responsabilite-cgv-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="responsabilite-cgv-heading"
        >
          Responsabilité
        </h2>
        <LegalPlaceholder>
          Limites de responsabilité d&apos;ArchiLAN et de HelloAsso en cas d&apos;incident
          technique, de force majeure ou d&apos;annulation.
        </LegalPlaceholder>
      </section>

      <section aria-labelledby="droit-cgv-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="droit-cgv-heading"
        >
          Droit applicable et litiges
        </h2>
        <p className="text-sm leading-7 text-muted-foreground">
          Les présentes CGV sont soumises au droit français. Tout litige peut être soumis à une
          médiation de la consommation avant tout recours judiciaire.
        </p>
        <LegalPlaceholder>
          Coordonnées du médiateur de la consommation compétent et procédure de saisine.
        </LegalPlaceholder>
      </section>
    </article>
  );
}
