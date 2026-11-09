import type { Metadata } from "next";

import { AdminSurveyPage } from "@/features/surveys/admin-survey-page";

export const metadata: Metadata = {
  title: "Sondage",
};

type Props = {
  params: Promise<{ eventId: string; surveyId: string }>;
};

export default function EventSurveyPage({ params }: Props) {
  return <AdminSurveyPage params={params} />;
}
