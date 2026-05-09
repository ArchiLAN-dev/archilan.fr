import type { Metadata } from "next";
import { RegistrationRecapGate } from "@/features/events/registration-recap-gate";

export const metadata: Metadata = {
  title: "Récapitulatif de l'inscription",
  description: "Vérifiez et confirmez votre inscription à l'événement ArchiLAN.",
  robots: { index: false, follow: false },
};

type RecapPageProps = {
  params: Promise<{ eventSlug: string; registrationId: string }>;
};

export default function RecapPage({ params }: RecapPageProps) {
  return (
    <div className="mx-auto max-w-3xl px-4 py-10 sm:px-6">
      <RegistrationRecapGate params={params} />
    </div>
  );
}
