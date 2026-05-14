import type { Metadata } from "next";
import Link from "next/link";

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
        <p className="text-sm text-muted-foreground">Version du 13 mai 2026</p>
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
        <div className="grid gap-3 text-sm leading-7 text-muted-foreground">
          <p>
            L&apos;accès au site archilan.fr est ouvert à toute personne physique sans
            restriction d&apos;âge particulière. La création d&apos;un compte utilisateur est
            ouverte à tout internaute souhaitant s&apos;inscrire à un événement ArchiLAN. Elle
            requiert uniquement la fourniture d&apos;une adresse e-mail valide.
          </p>
          <p>
            L&apos;utilisateur est seul responsable de la confidentialité de ses identifiants de
            connexion. La suppression du compte est accessible à tout moment depuis
            l&apos;espace personnel, section « Suppression du compte », sans démarche
            supplémentaire.
          </p>
        </div>
      </section>

      <section aria-labelledby="obligations-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="obligations-heading"
        >
          Obligations des utilisateurs
        </h2>
        <div className="grid gap-3 text-sm leading-7 text-muted-foreground">
          <p>En utilisant le site archilan.fr, l&apos;utilisateur s&apos;engage à :</p>
          <ul className="list-disc space-y-1 pl-5">
            <li>
              Fournir des informations exactes lors de la création de son compte et de ses
              inscriptions.
            </li>
            <li>Ne pas usurper l&apos;identité d&apos;un tiers ni diffuser de fausses informations.</li>
            <li>Ne pas perturber le bon déroulement des événements ou des sessions de jeu.</li>
            <li>Ne pas utiliser le service à des fins illicites ou contraires aux présentes CGU.</li>
            <li>
              Ne pas tenter de porter atteinte à l&apos;intégrité technique du site ou des
              services associés.
            </li>
          </ul>
          <p>
            ArchiLAN se réserve le droit de suspendre ou supprimer tout compte en cas de
            manquement avéré à ces obligations, sans préavis ni indemnité.
          </p>
        </div>
      </section>

      <section aria-labelledby="responsabilite-cgu-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="responsabilite-cgu-heading"
        >
          Responsabilité de l&apos;association
        </h2>
        <div className="grid gap-3 text-sm leading-7 text-muted-foreground">
          <p>
            ArchiLAN met en œuvre tous les moyens raisonnables pour assurer la disponibilité du
            site et de ses services. Toutefois, l&apos;association ne saurait garantir un accès
            ininterrompu ni la fiabilité absolue des informations publiées.
          </p>
          <p>ArchiLAN ne saurait être tenue responsable :</p>
          <ul className="list-disc space-y-1 pl-5">
            <li>
              Des contenus ou services proposés par des tiers (Twitch, Discord, Archipelago,
              HelloAsso).
            </li>
            <li>
              Des perturbations techniques indépendantes de sa volonté (panne réseau, incident
              hébergeur).
            </li>
            <li>De l&apos;impossibilité de tenir un événement du fait d&apos;un cas de force majeure.</li>
          </ul>
        </div>
      </section>

      <section aria-labelledby="modification-cgu-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="modification-cgu-heading"
        >
          Modification des CGU
        </h2>
        <div className="grid gap-3 text-sm leading-7 text-muted-foreground">
          <p>
            ArchiLAN se réserve le droit de modifier les présentes CGU à tout moment. Les
            utilisateurs disposant d&apos;un compte seront informés par e-mail de toute
            modification substantielle, avec un préavis minimum de 30 jours.
          </p>
          <p>
            La poursuite de l&apos;utilisation du site après ce délai vaut acceptation des
            nouvelles CGU. La version en vigueur est accessible à tout moment à
            l&apos;adresse{" "}
            <Link
              className="font-semibold text-accent-text hover:text-accent-text-hover"
              href="/cgu"
            >
              archilan.fr/cgu
            </Link>
            .
          </p>
        </div>
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
