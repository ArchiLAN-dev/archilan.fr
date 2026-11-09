import type { Metadata } from "next";

import { AdminEventSurveysPage } from "@/features/surveys/admin-event-surveys";

export const metadata: Metadata = {
  title: "Sondages de l'événement",
};

type Props = {
  params: Promise<{ eventId: string }>;
};

export default function EventSurveysPage({ params }: Props) {
  return <AdminEventSurveysPage params={params} />;
}
