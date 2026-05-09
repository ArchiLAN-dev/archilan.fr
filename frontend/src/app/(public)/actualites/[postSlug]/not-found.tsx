import type { Metadata } from "next";
import Link from "next/link";

export const metadata: Metadata = {
  title: "Article introuvable",
  robots: { index: false, follow: false },
};

export default function PostNotFound() {
  return (
    <section className="mx-auto grid max-w-2xl gap-5 py-20 text-center">
      <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">
        Article introuvable
      </p>
      <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">
        Cette actualité n&apos;est pas disponible.
      </h1>
      <p className="text-muted-foreground">
        Le contenu peut être non publié, archivé, ou le lien peut contenir une erreur.
      </p>
      <Link
        className="mx-auto inline-flex min-h-11 items-center justify-center rounded bg-accent px-5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
        href="/actualites"
      >
        Retour aux actualités
      </Link>
    </section>
  );
}
