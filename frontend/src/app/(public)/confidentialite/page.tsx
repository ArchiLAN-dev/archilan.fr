import type { Metadata } from "next";
import Link from "next/link";

export const metadata: Metadata = {
  title: "Politique de confidentialité",
  description:
    "Politique de confidentialité et traitement des données personnelles - ArchiLAN.",
  robots: { index: true, follow: true },
};

export default function ConfidentialitePage() {
  return (
    <article className="mx-auto grid max-w-3xl gap-12">
      <header className="grid gap-3 border-b border-border pb-8">
        <h1 className="font-heading text-4xl font-bold text-foreground">
          Politique de confidentialité
        </h1>
        <p className="text-sm leading-7 text-muted-foreground">
          ArchiLAN s&apos;engage à protéger vos données personnelles conformément au Règlement
          général sur la protection des données (RGPD - UE 2016/679) et à la loi Informatique
          et Libertés.
        </p>
        <p className="text-xs text-muted-foreground">
          Dernière mise à jour : 13 mai 2026
        </p>
      </header>

      {/* ── 1. Responsable du traitement ────────────────────────────────────── */}
      <section aria-labelledby="responsable-heading" className="grid gap-6">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="responsable-heading"
        >
          1. Responsable du traitement
        </h2>

        <dl className="divide-y divide-border card-glow rounded-lg border border-border">
          <LegalRow label="Nom">ArchiLAN - Association loi 1901 à but non lucratif</LegalRow>
          <LegalRow label="Adresse">
            26 rue de la Gantière, 63000 Clermont-Ferrand
          </LegalRow>
          <LegalRow label="Contact RGPD">
            <a
              className="text-accent-text hover:text-accent-text-hover"
              href="mailto:contact@archilan.fr"
            >
              contact@archilan.fr
            </a>
          </LegalRow>
        </dl>
      </section>

      {/* ── 2. Données collectées et finalités ──────────────────────────────── */}
      <section aria-labelledby="donnees-heading" className="grid gap-6">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="donnees-heading"
        >
          2. Données collectées et finalités
        </h2>

        <p className="text-sm leading-7 text-muted-foreground">
          ArchiLAN collecte uniquement les données strictement nécessaires à la gestion des
          événements et des inscriptions. Aucune donnée n&apos;est collectée à des fins
          publicitaires ou commerciales.
        </p>

        <div className="overflow-x-auto rounded-lg border border-border">
          <table className="w-full min-w-[480px] border-collapse text-sm">
            <thead className="border-b border-border bg-surface text-left text-muted-foreground">
              <tr>
                <th className="px-4 py-3 font-medium">Données</th>
                <th className="px-4 py-3 font-medium">Finalité</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              <ProcessingRow
                data="Adresse e-mail"
                purpose="Identification du compte, notifications d'événement, exercice des droits RGPD."
              />
              <ProcessingRow
                data="Pseudonyme (optionnel)"
                purpose="Affichage dans les listes d'inscrits et le backoffice."
              />
              <ProcessingRow
                data="Sélection de jeux Archipelago"
                purpose="Coordination de la session multijoueur lors de l'événement."
              />
              <ProcessingRow
                data="Statut et historique d'inscription"
                purpose="Gestion des places disponibles et suivi administratif des événements."
              />
              <ProcessingRow
                data="Données de paiement"
                purpose="Traitement des achats (cotisation, billetterie, boutique) via HelloAsso. ArchiLAN ne conserve pas de numéro de carte."
              />
              <ProcessingRow
                data="Journaux techniques (IP, horodatage)"
                purpose="Sécurité du service, détection d'anomalies, obligation légale."
              />
              <ProcessingRow
                data="Demandes d'exercice de droits RGPD"
                purpose="Traitement et archivage des demandes de droit d'accès, rectification, suppression, etc."
              />
            </tbody>
          </table>
        </div>
      </section>

      {/* ── 3. Bases légales ────────────────────────────────────────────────── */}
      <section aria-labelledby="bases-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="bases-heading"
        >
          3. Bases légales
        </h2>

        <dl className="divide-y divide-border card-glow rounded-lg border border-border text-sm">
          <LegalRow label="Exécution du contrat (CGU)">
            Compte utilisateur, inscription aux événements, sélection de jeux.
          </LegalRow>
          <LegalRow label="Intérêt légitime">
            Journaux de sécurité, intégrité et disponibilité du service.
          </LegalRow>
          <LegalRow label="Obligation légale">
            Conservation des données comptables liées aux transactions (HelloAsso), journaux réglementaires.
          </LegalRow>
          <LegalRow label="Consentement">
            Chargement de l&apos;intégration Twitch (contenu embarqué tiers). Le consentement
            peut être retiré à tout moment depuis le bas de chaque page.
          </LegalRow>
        </dl>
      </section>

      {/* ── 4. Durées de conservation ────────────────────────────────────────── */}
      <section aria-labelledby="retention-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="retention-heading"
        >
          4. Durées de conservation
        </h2>

        <dl className="divide-y divide-border card-glow rounded-lg border border-border text-sm">
          <LegalRow label="Données de compte actif">
            Conservées tant que le compte est actif ou jusqu&apos;à demande de suppression.
          </LegalRow>
          <LegalRow label="Après suppression du compte">
            30 jours
          </LegalRow>
          <LegalRow label="Historique d'inscriptions">
            3 ans à des fins d&apos;archivage associatif
          </LegalRow>
          <LegalRow label="Journaux techniques">
            12 mois conformément aux recommandations de la CNIL.
          </LegalRow>
          <LegalRow label="Données comptables (HelloAsso)">
            10 ans conformément à l&apos;obligation comptable légale (gérées par HelloAsso).
          </LegalRow>
          <LegalRow label="Demandes RGPD">
            5 ans
          </LegalRow>
        </dl>
      </section>

      {/* ── 5. Destinataires et sous-traitants ──────────────────────────────── */}
      <section aria-labelledby="destinataires-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="destinataires-heading"
        >
          5. Destinataires et sous-traitants
        </h2>

        <p className="text-sm leading-7 text-muted-foreground">
          Vos données ne sont pas vendues ni louées à des tiers. Elles peuvent être
          communiquées aux seuls prestataires techniques suivants, dans la limite nécessaire à
          leurs prestations :
        </p>

        <dl className="divide-y divide-border card-glow rounded-lg border border-border text-sm">
          <LegalRow label="HelloAsso">
            Traitement des paiements (cotisations, billetterie, boutique).
            HelloAsso applique sa propre politique de confidentialité.
          </LegalRow>
          <LegalRow label="Hébergeur de l'infrastructure">
            OVH SAS - serveurs situés en France
          </LegalRow>
          <LegalRow label="Twitch Interactive, Inc.">
            Uniquement si vous avez consenti à l&apos;intégration du lecteur Twitch. Données
            transmises vers les États-Unis dans le cadre du traitement Twitch.
          </LegalRow>
        </dl>
      </section>

      {/* ── 6. Vos droits ───────────────────────────────────────────────────── */}
      <section aria-labelledby="droits-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="droits-heading"
        >
          6. Vos droits
        </h2>

        <p className="text-sm leading-7 text-muted-foreground">
          Conformément au RGPD, vous disposez des droits suivants sur vos données :
        </p>

        <dl className="divide-y divide-border card-glow rounded-lg border border-border text-sm">
          <LegalRow label="Accès">Obtenir une copie des données vous concernant.</LegalRow>
          <LegalRow label="Rectification">Corriger des données inexactes ou incomplètes.</LegalRow>
          <LegalRow label="Effacement">
            Demander la suppression de vos données (droit à l&apos;oubli).
          </LegalRow>
          <LegalRow label="Limitation">
            Restreindre le traitement dans les cas prévus par le RGPD.
          </LegalRow>
          <LegalRow label="Portabilité">
            Recevoir vos données dans un format structuré et lisible.
          </LegalRow>
          <LegalRow label="Opposition">
            Vous opposer à un traitement fondé sur l&apos;intérêt légitime.
          </LegalRow>
          <LegalRow label="Retrait du consentement">
            Retirer à tout moment votre consentement aux intégrations tierces (Twitch) depuis le
            bas de chaque page.
          </LegalRow>
        </dl>

        <div className="card-glow rounded-lg border border-border p-4 text-sm leading-7 text-muted-foreground">
          <p>
            Pour exercer ces droits, rendez-vous dans votre{" "}
            <Link
              className="font-semibold text-accent-text hover:text-accent-text-hover"
              href="/compte"
            >
              espace personnel
            </Link>{" "}
            (section « Données et confidentialité »), ou contactez-nous à{" "}
            <a
              className="font-semibold text-accent-text hover:text-accent-text-hover"
              href="mailto:contact@archilan.fr"
            >
              contact@archilan.fr
            </a>
          </p>
          <p className="mt-2">
            Nous nous engageons à répondre dans un délai de{" "}
            <strong className="text-foreground">30 jours</strong>. La suppression de compte est
            accessible directement depuis{" "}
            <Link
              className="font-semibold text-accent-text hover:text-accent-text-hover"
              href="/compte"
            >
              votre espace personnel
            </Link>
            .
          </p>
          <ul className="mt-3 list-disc space-y-1 pl-5">
            <li>
              Demande RGPD :{" "}
              <Link
                className="font-semibold text-accent-text hover:text-accent-text-hover"
                href="/compte"
              >
                /compte
              </Link>
              , section Données et confidentialité.
            </li>
            <li>
              Suppression de compte :{" "}
              <Link
                className="font-semibold text-accent-text hover:text-accent-text-hover"
                href="/compte"
              >
                /compte
              </Link>
              , section Suppression du compte.
            </li>
          </ul>
        </div>
      </section>

      {/* ── 7. Cookies et stockage local ─────────────────────────────────────── */}
      <section aria-labelledby="cookies-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="cookies-heading"
        >
          7. Cookies et stockage local
        </h2>

        <div className="overflow-x-auto rounded-lg border border-border">
          <table className="w-full min-w-[480px] border-collapse text-sm">
            <thead className="border-b border-border bg-surface text-left text-muted-foreground">
              <tr>
                <th className="px-4 py-3 font-medium">Nom</th>
                <th className="px-4 py-3 font-medium">Type</th>
                <th className="px-4 py-3 font-medium">Durée</th>
                <th className="px-4 py-3 font-medium">Finalité</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              <tr>
                <td className="px-4 py-3 font-mono text-xs text-foreground">
                  auth_token
                </td>
                <td className="px-4 py-3 text-muted-foreground">Cookie httpOnly</td>
                <td className="px-4 py-3 text-muted-foreground">Session</td>
                <td className="px-4 py-3 text-muted-foreground">
                  Authentification (JWT). Strictement nécessaire.
                </td>
              </tr>
              <tr>
                <td className="px-4 py-3 font-mono text-xs text-foreground">
                  archilan_twitch_consent
                </td>
                <td className="px-4 py-3 text-muted-foreground">localStorage</td>
                <td className="px-4 py-3 text-muted-foreground">Permanent</td>
                <td className="px-4 py-3 text-muted-foreground">
                  Mémorise votre choix de consentement pour le lecteur Twitch intégré.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <p className="text-sm leading-7 text-muted-foreground">
          Aucun cookie publicitaire ou de pistage tiers n&apos;est déposé. Le lecteur Twitch
          (contenu tiers) n&apos;est chargé qu&apos;après consentement explicite.
        </p>
      </section>

      {/* ── 8. Modifications ────────────────────────────────────────────────── */}
      <section aria-labelledby="modifications-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="modifications-heading"
        >
          8. Modifications de la politique
        </h2>

        <p className="text-sm leading-7 text-muted-foreground">
          ArchiLAN se réserve le droit de modifier la présente politique à tout moment. En cas
          de modification substantielle, les utilisateurs disposant d&apos;un compte en seront
          informés par e-mail. La date de mise à jour en haut de page fait foi.
        </p>
      </section>

      {/* ── 9. Contact et réclamation CNIL ──────────────────────────────────── */}
      <section aria-labelledby="cnil-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="cnil-heading"
        >
          9. Réclamation auprès de la CNIL
        </h2>

        <p className="text-sm leading-7 text-muted-foreground">
          Si vous estimez que vos droits ne sont pas respectés, vous pouvez déposer une
          réclamation auprès de la Commission Nationale de l&apos;Informatique et des Libertés
          (CNIL) :
        </p>

        <dl className="divide-y divide-border card-glow rounded-lg border border-border text-sm">
          <LegalRow label="Site web">
            <a
              className="text-accent-text hover:text-accent-text-hover"
              href="https://www.cnil.fr"
              rel="noopener noreferrer"
              target="_blank"
            >
              www.cnil.fr
              <span className="sr-only"> (nouvel onglet)</span>
            </a>
          </LegalRow>
          <LegalRow label="Adresse">
            CNIL, 3 Place de Fontenoy - TSA 80715 - 75334 Paris Cedex 07
          </LegalRow>
          <LegalRow label="Téléphone">+33 (0)1 53 73 22 22</LegalRow>
        </dl>
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
    <div className="grid gap-1 px-4 py-3 sm:grid-cols-[14rem_1fr] sm:items-start sm:gap-4">
      <dt className="text-sm font-medium text-muted-foreground">{label}</dt>
      <dd className="text-sm text-foreground">{children}</dd>
    </div>
  );
}

function ProcessingRow({ data, purpose }: { data: string; purpose: string }) {
  return (
    <tr>
      <td className="px-4 py-3 font-medium text-foreground">{data}</td>
      <td className="px-4 py-3 text-muted-foreground">{purpose}</td>
    </tr>
  );
}
