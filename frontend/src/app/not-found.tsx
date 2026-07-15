import type { Metadata } from "next";
import Link from "next/link";

export const metadata: Metadata = {
  title: "Page introuvable",
  robots: { index: false, follow: false },
};

export default function NotFound() {
  return (
    <section className="mx-auto grid min-h-[60vh] max-w-2xl content-center gap-5 py-20 text-center">
      <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">
        Erreur 404
      </p>
      <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">
        Cette page n&apos;existe pas.
      </h1>
      <p className="text-muted-foreground">
        Le lien est peut-être erroné ou la page a été déplacée. Reviens à l&apos;accueil ou consulte
        les événements à venir.
      </p>
      <div className="mx-auto flex flex-col gap-3 sm:flex-row">
        <Link
          className="inline-flex min-h-11 items-center justify-center rounded bg-accent px-5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
          href="/"
        >
          Retour à l&apos;accueil
        </Link>
        <Link
          className="inline-flex min-h-11 items-center justify-center rounded border border-border px-5 text-sm font-semibold text-foreground transition-colors hover:border-accent"
          href="/evenements"
        >
          Voir les événements
        </Link>
      </div>
    </section>
  );
}
