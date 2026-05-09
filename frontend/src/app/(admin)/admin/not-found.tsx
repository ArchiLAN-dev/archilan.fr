import type { Metadata } from "next";
import Link from "next/link";

export const metadata: Metadata = {
  title: "Page introuvable",
  robots: { index: false, follow: false },
};

export default function AdminNotFound() {
  return (
    <section className="mx-auto grid max-w-2xl gap-5 py-20 text-center">
      <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">
        Page introuvable
      </h1>
      <p className="text-muted-foreground">
        Cette section n&apos;est pas disponible.
      </p>
      <Link
        className="mx-auto inline-flex min-h-11 items-center justify-center rounded bg-accent px-5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
        href="/"
      >
        Retour à l&apos;accueil
      </Link>
    </section>
  );
}
