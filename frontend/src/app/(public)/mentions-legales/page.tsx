import type { Metadata } from "next";
import { LegalField, LegalPlaceholder } from "@/components/legal-placeholder";

export const metadata: Metadata = {
  title: "Mentions légales",
  description:
    "Mentions légales de l'association ArchiLAN - identité de l'éditeur, hébergement et propriété intellectuelle.",
  robots: { index: true, follow: true },
};

export default function MentionsLegalesPage() {
  return (
    <article className="mx-auto grid max-w-3xl gap-12">
      <header className="grid gap-2 border-b border-border pb-8">
        <h1 className="font-heading text-4xl font-bold text-foreground">Mentions légales</h1>
        <p className="text-sm text-muted-foreground">
          Conformément à la loi n° 2004-575 du 21 juin 2004 pour la confiance dans
          l&apos;économie numérique (LCEN).
        </p>
        <LegalPlaceholder>
          Les champs marques Requis doivent etre completes avec les informations officielles de
          l&apos;association et de l&apos;hebergeur avant publication definitive.
        </LegalPlaceholder>
      </header>

      {/* ── Éditeur ────────────────────────────────────────────────────────── */}
      <section aria-labelledby="editeur-heading" className="grid gap-6">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="editeur-heading"
        >
          Éditeur du site
        </h2>

        <dl className="divide-y divide-border card-glow rounded-lg border border-border">
          <LegalRow label="Dénomination sociale">ArchiLAN</LegalRow>

          <LegalRow label="Forme juridique">
            Association loi 1901 à but non lucratif
          </LegalRow>

          <LegalRow label="Objet social">
            Organisation d&apos;événements de jeux vidéo coopératifs autour du protocole
            Archipelago en France.
          </LegalRow>

          <LegalRow label="Site web">archilan.fr</LegalRow>

          <LegalRow label="Siège social">
            <LegalField label="Adresse postale complète du siège de l'association." />
          </LegalRow>

          <LegalRow label="Numéro RNA">
            <LegalField label="Numéro W… d'enregistrement au répertoire national des associations." />
          </LegalRow>

          <LegalRow label="Directeur de la publication">
            <LegalField label="Prénom et nom du responsable légal (ex. président·e en exercice)." />
          </LegalRow>

          <LegalRow label="Adresse e-mail">
            <LegalField label="Adresse e-mail officielle de contact de l'association." />
          </LegalRow>

          <LegalRow label="Téléphone">
            <LegalField label="Numéro de téléphone de contact de l'association, si disponible." />
          </LegalRow>
        </dl>
      </section>

      {/* ── Hébergement ────────────────────────────────────────────────────── */}
      <section aria-labelledby="hebergement-heading" className="grid gap-6">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="hebergement-heading"
        >
          Hébergement
        </h2>

        <dl className="divide-y divide-border card-glow rounded-lg border border-border">
          <LegalRow label="Prestataire">
            <LegalField label="Raison sociale de l'hébergeur du site et de l'API." />
          </LegalRow>

          <LegalRow label="Forme juridique / immatriculation">
            <LegalField label="Forme juridique, SIREN/SIRET ou identifiant équivalent de l'hébergeur." />
          </LegalRow>

          <LegalRow label="Adresse">
            <LegalField label="Adresse postale complète de l'hébergeur." />
          </LegalRow>

          <LegalRow label="Téléphone">
            <LegalField label="Numéro de téléphone de l'hébergeur." />
          </LegalRow>

          <LegalRow label="Site web">
            <LegalField label="URL du site de l'hébergeur." />
          </LegalRow>
        </dl>
      </section>

      {/* ── Propriété intellectuelle ────────────────────────────────────────── */}
      <section aria-labelledby="pi-heading" className="grid gap-4">
        <h2 className="font-heading text-2xl font-semibold text-foreground" id="pi-heading">
          Propriété intellectuelle
        </h2>

        <p className="text-sm leading-7 text-muted-foreground">
          L&apos;ensemble des contenus diffusés sur ce site - textes, logos, photographies,
          vidéos et code source - sont la propriété de l&apos;association ArchiLAN ou de leurs
          auteurs respectifs et sont protégés par le droit d&apos;auteur français.
        </p>

        <p className="text-sm leading-7 text-muted-foreground">
          Toute reproduction, représentation, modification ou exploitation totale ou partielle
          de ces contenus, sans autorisation expresse et préalable de l&apos;association, est
          strictement interdite et constituerait une contrefaçon sanctionnée par les articles
          L.335-2 et suivants du Code de la propriété intellectuelle.
        </p>

        <LegalPlaceholder>
          Si le site utilise des ressources tierces sous licence ouverte (polices, icônes,
          illustrations), lister ici les licences et leurs auteurs.
        </LegalPlaceholder>
      </section>

      {/* ── Liens hypertextes ──────────────────────────────────────────────── */}
      <section aria-labelledby="liens-heading" className="grid gap-4">
        <h2 className="font-heading text-2xl font-semibold text-foreground" id="liens-heading">
          Liens hypertextes
        </h2>

        <p className="text-sm leading-7 text-muted-foreground">
          Le site archilan.fr peut contenir des liens vers des sites tiers (Twitch, Discord,
          HelloAsso, Archipelago). ArchiLAN n&apos;exerce aucun contrôle sur ces sites et
          décline toute responsabilité quant à leur contenu ou leur politique de
          confidentialité.
        </p>
      </section>
    </article>
  );
}

function LegalRow({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="grid gap-1 px-4 py-3 sm:grid-cols-[11rem_1fr] sm:items-start sm:gap-4">
      <dt className="text-sm font-medium text-muted-foreground">{label}</dt>
      <dd className="text-sm text-foreground">{children}</dd>
    </div>
  );
}
