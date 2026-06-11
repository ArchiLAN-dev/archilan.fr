import type { Metadata } from "next";
import Link from "next/link";
import { AlertCircle, RefreshCw } from "lucide-react";
import { getMembershipCheckoutUrl } from "@/features/payments/membership-api";
import { MembershipCheckout } from "@/features/payments/membership-checkout";

export const metadata: Metadata = {
  title: "Adhésion",
  description:
    "Rejoins l'association ArchiLAN en payant ta cotisation annuelle via HelloAsso.",
  openGraph: {
    title: "Adhésion - ArchiLAN",
  },
};

export default async function AdhesionPage() {
  const checkoutEmbedUrl = await getMembershipCheckoutUrl();

  return (
    <div className="mx-auto grid max-w-3xl gap-12 px-4 py-12">
      <header>
        <p className="mb-4 text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">
          Association ArchiLAN
        </p>
        <h1 className="font-heading text-4xl font-bold leading-tight text-foreground md:text-5xl">
          Adhésion
        </h1>
        <p className="mt-5 max-w-2xl text-lg leading-8 text-muted-foreground">
          Deviens membre de l&apos;association ArchiLAN et soutiens l&apos;organisation des
          événements Archipelago. La cotisation annuelle est fixée par le bureau de
          l&apos;association.
        </p>
      </header>

      {checkoutEmbedUrl ? (
        <MembershipCheckout checkoutEmbedUrl={checkoutEmbedUrl} />
      ) : (
        <div className="flex items-start gap-4 card-glow rounded-lg border border-border p-6">
          <AlertCircle
            aria-hidden="true"
            className="mt-0.5 size-5 shrink-0 text-muted-foreground"
          />
          <div>
            <p className="font-semibold text-foreground">
              Adhésion temporairement indisponible
            </p>
            <p className="mt-1 text-sm leading-6 text-muted-foreground">
              Le formulaire de cotisation n&apos;est pas encore configuré. Reviens bientôt ou
              contacte-nous directement via Discord pour adhérer.
            </p>
            <Link
              className="mt-4 inline-flex min-h-11 items-center justify-center gap-2 rounded border border-border bg-background px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
              href="/adhesion"
            >
              <RefreshCw aria-hidden="true" className="size-4" />
              Réessayer
            </Link>
          </div>
        </div>
      )}
    </div>
  );
}
