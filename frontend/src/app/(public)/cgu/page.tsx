import type { Metadata } from "next";
import { LegalPlaceholder } from "@/components/legal-placeholder";

export const metadata: Metadata = {
  title: "Conditions Générales d'Utilisation",
  description:
    "Conditions générales d'utilisation du site et des services ArchiLAN.",
  robots: { index: true, follow: true },
};

export default function CguPage() {
  return (
    <article className="mx-auto grid max-w-3xl gap-10">
      <header className="grid gap-2 border-b border-border pb-8">
        <h1 className="font-heading text-4xl font-bold text-foreground">
          Conditions Générales d&apos;Utilisation
        </h1>
        <p className="text-sm text-muted-foreground">
          Version du 2 mai 2026 - contenu à compléter avant publication.
        </p>
      </header>

      <section aria-labelledby="objet-cgu-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="objet-cgu-heading"
        >
          Objet
        </h2>
        <p className="text-sm leading-7 text-muted-foreground">
          Les présentes CGU régissent l&apos;accès et l&apos;utilisation du site archilan.fr et de
          ses services par toute personne souhaitant créer un compte ou s&apos;inscrire à un
          événement.
        </p>
      </section>

      <section aria-labelledby="acces-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="acces-heading"
        >
          Accès et création de compte
        </h2>
        <LegalPlaceholder>
          Conditions d&apos;accès au service (âge minimum, résidence, etc.). Modalités de création
          et de suppression de compte.
        </LegalPlaceholder>
      </section>

      <section aria-labelledby="obligations-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="obligations-heading"
        >
          Obligations des utilisateurs
        </h2>
        <LegalPlaceholder>
          Règles de conduite, interdictions (contenu illicite, usurpation d&apos;identité,
          perturbation des événements), responsabilité de l&apos;utilisateur pour les informations
          saisies.
        </LegalPlaceholder>
      </section>

      <section aria-labelledby="responsabilite-cgu-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="responsabilite-cgu-heading"
        >
          Responsabilité de l&apos;association
        </h2>
        <LegalPlaceholder>
          Limites de responsabilité d&apos;ArchiLAN concernant la disponibilité du service, les
          contenus tiers et les événements.
        </LegalPlaceholder>
      </section>

      <section aria-labelledby="modification-cgu-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="modification-cgu-heading"
        >
          Modification des CGU
        </h2>
        <LegalPlaceholder>
          Modalités de notification des changements, délai de préavis et conditions
          d&apos;acceptation des nouvelles CGU.
        </LegalPlaceholder>
      </section>

      <section aria-labelledby="droit-cgu-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="droit-cgu-heading"
        >
          Droit applicable et litiges
        </h2>
        <p className="text-sm leading-7 text-muted-foreground">
          Les présentes CGU sont soumises au droit français. Tout litige relatif à leur
          interprétation ou exécution relève de la compétence des tribunaux compétents.
        </p>
      </section>
    </article>
  );
}
