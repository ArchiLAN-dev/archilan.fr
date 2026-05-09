import type { Metadata } from "next";
import { RegistrationEligibilityGate } from "@/features/events/registration-eligibility-gate";

export const metadata: Metadata = {
  title: "Inscription",
  description: "Vérification de l'éligibilité et démarrage de l'inscription à l'événement ArchiLAN.",
  robots: { index: false, follow: false },
};

type InscriptionPageProps = {
  params: Promise<{ eventSlug: string }>;
};

export default function InscriptionPage({ params }: InscriptionPageProps) {
  return (
    <div className="mx-auto max-w-3xl px-4 py-10 sm:px-6">
      <RegistrationEligibilityGate params={params} />
    </div>
  );
}
