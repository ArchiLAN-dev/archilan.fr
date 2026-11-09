import type { Metadata } from "next";
import { SurveyResponseGate } from "@/features/surveys/survey-response-form";

export const metadata: Metadata = {
  title: "Sondage de l'événement",
  description: "Réponds aux questions de l'organisation de l'événement ArchiLAN.",
  openGraph: { title: "Sondage de l'événement" },
  robots: { index: false, follow: false },
};

type SurveyPageProps = {
  params: Promise<{ eventSlug: string; surveyId: string }>;
};

/** Story 51.2: a member answers a survey of an event. */
export default function SurveyPage({ params }: SurveyPageProps) {
  return (
    <div className="mx-auto max-w-2xl px-4 py-10 sm:px-6">
      <SurveyResponseGate params={params} />
    </div>
  );
}
