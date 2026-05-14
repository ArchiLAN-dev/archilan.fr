import type { Metadata } from "next";

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
        <p className="text-sm text-muted-foreground">Version du 13 mai 2026</p>
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
        <div className="grid gap-3 text-sm leading-7 text-muted-foreground">
          <p>
            Tous les prix affichés sur archilan.fr sont indiqués en euros, toutes taxes
            comprises. ArchiLAN étant une association à but non lucratif, ses ventes ne sont
            pas soumises à la TVA.
          </p>
          <p>
            HelloAsso peut proposer à l&apos;acheteur, à sa seule discrétion, d&apos;ajouter une
            contribution au fonctionnement de la plateforme HelloAsso. Cette contribution
            n&apos;est pas reversée à ArchiLAN et reste entièrement facultative.
          </p>
        </div>
      </section>

      <section aria-labelledby="commande-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="commande-heading"
        >
          Commande et paiement
        </h2>
        <div className="grid gap-3 text-sm leading-7 text-muted-foreground">
          <p>
            Les achats (billetterie, cotisations d&apos;adhésion, boutique) sont effectués
            exclusivement via la plateforme HelloAsso. L&apos;acheteur est redirigé vers
            HelloAsso pour finaliser son paiement par carte bancaire. ArchiLAN n&apos;a accès à
            aucune donnée de carte bancaire.
          </p>
          <p>
            Un justificatif de paiement est transmis automatiquement par HelloAsso à
            l&apos;adresse e-mail fournie lors de la transaction. L&apos;inscription est confirmée
            dès réception du paiement par HelloAsso.
          </p>
        </div>
      </section>

      <section aria-labelledby="remboursement-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="remboursement-heading"
        >
          Remboursement et annulation
        </h2>
        <div className="grid gap-3 text-sm leading-7 text-muted-foreground">
          <p>
            <strong className="text-foreground">Annulation par ArchiLAN :</strong> en cas
            d&apos;annulation d&apos;un événement, les participants inscrits sont remboursés du
            montant payé (hors contribution HelloAsso) dans un délai de 14 jours via HelloAsso.
          </p>
          <p>
            <strong className="text-foreground">
              Annulation à l&apos;initiative de l&apos;acheteur :
            </strong>{" "}
            les inscriptions ne sont pas remboursables, sauf disposition contraire précisée
            explicitement lors de l&apos;événement concerné.
          </p>
          <p>
            <strong className="text-foreground">Droit de rétractation :</strong> conformément
            à l&apos;article L.221-28 du Code de la consommation, le droit de rétractation de
            14 jours ne s&apos;applique pas aux prestations de loisirs (billetterie, événements)
            fournies à une date déterminée.
          </p>
        </div>
      </section>

      <section aria-labelledby="responsabilite-cgv-heading" className="grid gap-4">
        <h2
          className="font-heading text-2xl font-semibold text-foreground"
          id="responsabilite-cgv-heading"
        >
          Responsabilité
        </h2>
        <div className="grid gap-3 text-sm leading-7 text-muted-foreground">
          <p>
            ArchiLAN ne saurait être tenue responsable des dysfonctionnements techniques de la
            plateforme HelloAsso lors du traitement du paiement. En pareil cas, l&apos;acheteur
            est invité à contacter directement HelloAsso.
          </p>
          <p>
            En cas de force majeure (catastrophe naturelle, pandémie, défaillance
            d&apos;infrastructure, interdiction administrative) rendant impossible la tenue
            d&apos;un événement, ArchiLAN en informera les participants dans les meilleurs délais
            et organisera les remboursements selon les possibilités offertes par HelloAsso.
          </p>
        </div>
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
        <div className="grid gap-3 text-sm leading-7 text-muted-foreground">
          <p>
            En cas de litige non résolu amiablement, l&apos;acheteur peut recourir gratuitement
            à un médiateur de la consommation. ArchiLAN a désigné :
          </p>
          <dl className="divide-y divide-border card-glow rounded-lg border border-border">
            <MediatorRow label="Médiateur">
              Association Nationale des Médiateurs (ANM)
            </MediatorRow>
            <MediatorRow label="Adresse">2 rue de Colmar, 94300 Vincennes</MediatorRow>
            <MediatorRow label="Site web">
              <a
                className="text-accent-text hover:text-accent-text-hover"
                href="https://www.anm-mediation.com"
                rel="noopener noreferrer"
                target="_blank"
              >
                www.anm-mediation.com
                <span className="sr-only"> (nouvel onglet)</span>
              </a>
            </MediatorRow>
          </dl>
          <p>
            La plateforme européenne de règlement en ligne des litiges est également accessible
            à :{" "}
            <a
              className="text-accent-text hover:text-accent-text-hover"
              href="https://ec.europa.eu/consumers/odr"
              rel="noopener noreferrer"
              target="_blank"
            >
              ec.europa.eu/consumers/odr
              <span className="sr-only"> (nouvel onglet)</span>
            </a>
            .
          </p>
        </div>
      </section>
    </article>
  );
}

function MediatorRow({
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
