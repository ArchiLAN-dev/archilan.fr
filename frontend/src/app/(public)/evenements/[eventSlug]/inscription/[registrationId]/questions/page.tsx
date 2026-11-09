import type { Metadata } from "next";

import { RegistrationQuestionsGate } from "@/features/surveys/registration-questions";

export const metadata: Metadata = {
  title: "Questions de l'orga",
  description: "Les questions de l'orga avant de confirmer ton inscription à l'événement ArchiLAN.",
  robots: { index: false, follow: false },
};

type QuestionsPageProps = {
  params: Promise<{ eventSlug: string; registrationId: string }>;
};

export default function QuestionsPage({ params }: QuestionsPageProps) {
  return (
    <div className="mx-auto max-w-content px-4 py-10 sm:px-6">
      <RegistrationQuestionsGate params={params} />
    </div>
  );
}
